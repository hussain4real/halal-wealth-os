<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AvailabilitySources
{
    /** Complete totals; bounded disclosure. Caller holds the shared owner boundary.
     * @param list<string> $investmentGoals
     * @param list<string> $overlapReservations
     * @return array<string,mixed> */
    public function read(User $owner, string $utcDate, array $investmentGoals, array $overlapReservations): array
    {
        $row = DB::selectOne(<<<'SQL'
WITH parameters AS (SELECT ?::bigint AS owner_id,?::date AS today,?::jsonb AS investments,?::jsonb AS overlap_ids),
cash AS (
 SELECT a.id,a.name,a.currency_code AS currency,a.archived_at,
 coalesce(m.minor,0) AS minor,
 CASE WHEN i.id IS NOT NULL AND opening.id IS NULL THEN 'opening_pending'
 WHEN opening.effective_date>parameters.today THEN 'opening_after_cutoff'
 WHEN coalesce(m.minor,0)<-9223372036854775808 OR coalesce(m.minor,0)>9223372036854775807 THEN 'out_of_range'
 WHEN EXISTS(SELECT 1 FROM postings p JOIN journal_entries original ON original.id=p.journal_id
  JOIN journal_entries cancellation ON cancellation.reverses_journal_id=original.id
  WHERE p.account_id=a.id AND p.owner_id=a.owner_id AND cancellation.status IN ('posted','reversed')
  AND cancellation.effective_date<>original.effective_date
  AND parameters.today>=least(original.effective_date,cancellation.effective_date)
  AND parameters.today<greatest(original.effective_date,cancellation.effective_date)
  AND (EXISTS(SELECT 1 FROM journal_entries replacement WHERE replacement.replaces_journal_id=original.id AND replacement.status IN ('posted','reversed'))
   OR EXISTS(SELECT 1 FROM fx_operation_journals member JOIN fx_operations replacement ON replacement.replaces_id=member.operation_id WHERE member.journal_id=original.id AND replacement.status IN ('posted','reversed'))))
 THEN 'legacy_correction_timing' END AS reason
 FROM parameters JOIN accounts a ON a.owner_id=parameters.owner_id
 LEFT JOIN opening_balance_intents i ON i.account_id=a.id
 LEFT JOIN opening_balance_postings op ON op.opening_intent_id=i.id
 LEFT JOIN journal_entries opening ON opening.id=op.journal_id AND opening.status IN ('posted','reversed')
 LEFT JOIN LATERAL(SELECT sum(p.amount_minor::numeric) AS minor FROM postings p JOIN journal_entries j ON j.id=p.journal_id
  WHERE p.account_id=a.id AND p.owner_id=a.owner_id AND j.owner_id=a.owner_id AND j.status IN ('posted','reversed') AND j.effective_date<=parameters.today) m ON true
 WHERE a.system_role IS NULL AND a.type IN ('bank','cash')
), current_goals AS (
 SELECT root.id,root.account_id,root.mode,root.currency,g.name,g.closed,g.policy,g.contribution_minor
 FROM parameters JOIN goal_plans root ON root.owner_id=parameters.owner_id AND root.supersedes_id IS NULL
 JOIN goal_plans g ON g.family_reference=root.family_reference AND g.owner_id=root.owner_id
 WHERE NOT EXISTS(SELECT 1 FROM goal_plans n WHERE n.supersedes_id=g.id)
), active AS (
 SELECT r.id,r.goal_id,r.account_id,g.currency,r.amount_minor::numeric AS nominal,
 coalesce(sum(r.amount_minor::numeric) OVER(PARTITION BY r.account_id ORDER BY r.id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) AS earlier
 FROM parameters JOIN goal_reservations r ON r.owner_id=parameters.owner_id
 JOIN current_goals g ON g.id=r.goal_id AND NOT g.closed
 WHERE NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id)
), reservations AS (
 SELECT r.*,CASE WHEN c.reason IS NULL THEN least(r.nominal,greatest(0,c.minor-r.earlier)) END AS backed
 FROM active r JOIN cash c ON c.id=r.account_id
), account_values AS (
 SELECT c.*,
 CASE WHEN EXISTS(SELECT 1 FROM current_goals g WHERE g.account_id=c.id AND g.mode='dedicated' AND NOT g.closed)
 THEN greatest(0,c.minor) ELSE coalesce(r.backed,0) END AS protected,
 coalesce(r.nominal,0) AS nominal_reserved,coalesce(r.shortfall,0) AS reservation_shortfall,
 coalesce(r.investment_backed,0) AS investment_backed
 FROM cash c LEFT JOIN LATERAL(SELECT sum(r.backed) AS backed,sum(r.nominal) AS nominal,sum(r.nominal-r.backed) AS shortfall,
 sum(CASE WHEN jsonb_exists((SELECT investments FROM parameters),r.goal_id::text) THEN r.backed ELSE 0 END) AS investment_backed
 FROM reservations r WHERE r.account_id=c.id) r ON true
), groups AS (
 SELECT currency,sum(minor)::text AS cash,sum(protected)::text AS protected,sum(nominal_reserved)::text AS nominal_reserved,
 sum(reservation_shortfall)::text AS reservation_shortfall,sum(investment_backed)::text AS investment_backed,
 string_agg(DISTINCT reason,',' ORDER BY reason) AS reason,count(*) AS account_count FROM account_values GROUP BY currency
), contributions AS (
 SELECT g.currency,sum(CASE WHEN g.policy='fixed_monthly' THEN g.contribution_minor::numeric ELSE 0 END)::text AS minor,
 count(*) FILTER(WHERE g.policy='variable') AS variable_count
 FROM current_goals g,parameters WHERE NOT g.closed AND NOT(jsonb_exists(parameters.investments,g.id::text)) GROUP BY g.currency
)
SELECT jsonb_build_object(
 'groups',coalesce((SELECT jsonb_agg(to_jsonb(g) ORDER BY currency) FROM groups g),'[]'::jsonb),
 'accounts',coalesce((SELECT jsonb_agg(to_jsonb(a) ORDER BY id) FROM (SELECT id::text,name,currency,minor::text,protected::text,nominal_reserved::text,reservation_shortfall::text,reason,archived_at IS NOT NULL AS archived FROM account_values ORDER BY id LIMIT 20) a),'[]'::jsonb),
 'account_count',(SELECT count(*) FROM cash),
 'reservations',coalesce((SELECT jsonb_object_agg(r.id::text,jsonb_build_object('minor',r.backed::text,'currency',r.currency)) FROM reservations r,parameters WHERE jsonb_exists(parameters.overlap_ids,r.id::text)),'{}'::jsonb),
 'contributions',coalesce((SELECT jsonb_agg(to_jsonb(c) ORDER BY currency) FROM contributions c),'[]'::jsonb),
 'source_watermark',(SELECT coalesce(max(j.id),0)::text FROM journal_entries j,parameters WHERE j.owner_id=parameters.owner_id),
 'goal_watermark',(SELECT coalesce(max(g.id),0)::text FROM goal_plans g,parameters WHERE g.owner_id=parameters.owner_id),
 'reservation_watermark',(SELECT coalesce(max(r.id),0)::text FROM goal_reservations r,parameters WHERE r.owner_id=parameters.owner_id),
 'release_watermark',(SELECT coalesce(max(r.id),0)::text FROM goal_releases r,parameters WHERE r.owner_id=parameters.owner_id)
) AS data
SQL, [$owner->id, $utcDate, json_encode($investmentGoals, JSON_THROW_ON_ERROR), json_encode($overlapReservations, JSON_THROW_ON_ERROR)]);

        return json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
    }
}
