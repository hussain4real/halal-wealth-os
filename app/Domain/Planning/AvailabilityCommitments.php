<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AvailabilityCommitments
{
    /** Full recurrence and allocation aggregates, including overdue/unmaterialized occurrences.
     * @param list<array<string,mixed>> $requirements
     * @param list<array<string,mixed>> $overlaps
     * @param array<string,array{minor:?string,currency:string}> $reservations
     * @return array<string,mixed> */
    public function read(User $owner, string $utcDate, string $today, string $horizon, array $requirements, array $overlaps, array $reservations): array
    {
        $next = CarbonImmutable::parse(substr($today, 0, 7).'-01', BudgetCalendar::ZONE)->addMonth();
        $start = $next->toDateString();
        $end = $next->endOfMonth()->toDateString();
        $budget = DB::selectOne(<<<'SQL'
WITH versions AS (
 SELECT s.*,c.direction,lead(s.cutover_date) OVER(PARTITION BY s.family_reference ORDER BY s.version) AS next_cutover
 FROM planning_schedules s JOIN planning_categories c ON c.id=s.category_id WHERE s.owner_id=?
), selected AS (
 SELECT *,least(?::date,coalesce(end_date,?::date),coalesce(next_cutover-1,?::date)) AS last_date FROM versions s
 WHERE (s.direction='income' AND s.salary) OR (s.direction='expense' AND EXISTS(SELECT 1 FROM jsonb_to_recordset(?::jsonb) AS r(family text,required boolean) WHERE r.family=s.family_reference AND r.required))
)
SELECT coalesce(sum(CASE WHEN first_due_date>last_date THEN 0 WHEN interval_months=0 THEN 1 ELSE
 ((extract(year FROM last_date)::int-extract(year FROM first_due_date)::int)*12+extract(month FROM last_date)::int-extract(month FROM first_due_date)::int)/interval_months+1 END),0)::text AS candidates FROM selected
SQL, [$owner->id, max($horizon, $end), max($horizon, $end), max($horizon, $end), json_encode($requirements, JSON_THROW_ON_ERROR)]);
        if (bccomp(PlanningInput::minor($budget->candidates), '100000', 0) > 0) {
            return ['groups' => [], 'occurrences' => [], 'occurrence_count' => null, 'month_start' => $start, 'month_end' => $end, 'reason' => 'projection_range_exceeded'];
        }
        $links = array_map(fn (array $link): array => [...$link, 'backed' => $reservations[$link['reservation_id']]['minor'] ?? '0'], $overlaps);
        $row = DB::selectOne(<<<'SQL'
WITH parameters AS (SELECT ?::bigint AS owner_id,?::date AS cutoff_date,?::date AS horizon,?::date AS month_start,?::date AS month_end,?::jsonb AS classifications,?::jsonb AS links),
classifications AS (SELECT r.* FROM parameters,jsonb_to_recordset(parameters.classifications) AS r(family text,required boolean)),
versions AS (
 SELECT s.*,c.direction,lead(s.cutover_date) OVER(PARTITION BY s.family_reference ORDER BY s.version) AS next_cutover
 FROM parameters JOIN planning_schedules s ON s.owner_id=parameters.owner_id JOIN planning_categories c ON c.id=s.category_id
), rules AS (
 SELECT s.*,least(greatest(parameters.horizon,parameters.month_end),coalesce(s.end_date,greatest(parameters.horizon,parameters.month_end)),coalesce(s.next_cutover-1,greatest(parameters.horizon,parameters.month_end))) AS last_date
 FROM versions s CROSS JOIN parameters WHERE (s.direction='income' AND s.salary) OR (s.direction='expense' AND EXISTS(SELECT 1 FROM classifications r WHERE r.family=s.family_reference AND r.required))
), dates AS (
 SELECT s.*, (s.first_due_date+make_interval(months=>s.interval_months*n))::date AS due
 FROM rules s,LATERAL generate_series(0,CASE WHEN s.first_due_date>s.last_date THEN -1 WHEN s.interval_months=0 THEN 0 ELSE
 greatest(-1,((extract(year FROM s.last_date)::int-extract(year FROM s.first_due_date)::int)*12
 +extract(month FROM s.last_date)::int-extract(month FROM s.first_due_date)::int)/s.interval_months) END) n
), valid AS (
 SELECT d.* FROM dates d,parameters WHERE d.due<=greatest(parameters.horizon,parameters.month_end)
 AND (d.end_date IS NULL OR d.due<=d.end_date) AND (d.next_cutover IS NULL OR d.due<d.next_cutover)
), paid AS (
 SELECT o.schedule_id,o.due_date,sum(a.amount_minor::numeric) AS used
 FROM parameters JOIN planning_occurrences o ON o.owner_id=parameters.owner_id
 JOIN planning_allocations a ON a.occurrence_id=o.id AND a.owner_id=o.owner_id
 JOIN journal_entries j ON j.id=a.journal_id AND j.owner_id=o.owner_id
 WHERE j.status='posted' AND j.effective_date<=parameters.cutoff_date
 AND NOT EXISTS(SELECT 1 FROM planning_releases r WHERE r.allocation_id=a.id)
 GROUP BY o.schedule_id,o.due_date
), occurrences AS (
 SELECT v.id AS schedule_id,v.name,v.currency,v.direction,v.due,v.expected_minor::numeric AS expected,
 coalesce(p.used,0) AS paid,greatest(0,v.expected_minor::numeric-coalesce(p.used,0)) AS remaining
 FROM valid v LEFT JOIN paid p ON p.schedule_id=v.id AND p.due_date=v.due
), configured_links AS (
 SELECT l.reservation_id,l.schedule_id,l.due_date,l.minor::numeric AS amount,l.backed::numeric AS backed
 FROM parameters,jsonb_to_recordset(parameters.links) AS l(reservation_id bigint,schedule_id bigint,due_date date,minor text,backed text)
), actual_values AS (
 SELECT o.*,0::numeric AS overlap,o.remaining AS unfunded,
 o.direction='expense' AND o.due<=parameters.horizon AS due_now,
 o.due BETWEEN parameters.month_start AND parameters.month_end AS next_month
 FROM occurrences o CROSS JOIN parameters
), groups AS (
 SELECT currency,
 coalesce(sum(remaining) FILTER(WHERE due_now),0)::text AS required,
 coalesce(sum(overlap) FILTER(WHERE due_now),0)::text AS overlap,
 coalesce(sum(unfunded) FILTER(WHERE due_now),0)::text AS unfunded,
 coalesce(sum(remaining) FILTER(WHERE next_month AND direction='expense'),0)::text AS monthly_commitments,
 coalesce(sum(overlap) FILTER(WHERE next_month AND direction='expense'),0)::text AS monthly_overlap,
 coalesce(sum(remaining) FILTER(WHERE next_month AND direction='income'),0)::text AS salary,
 count(*) FILTER(WHERE next_month AND direction='income') AS salary_count,
 count(*) FILTER(WHERE due_now) AS occurrence_count
 FROM actual_values GROUP BY currency
)
SELECT jsonb_build_object(
 'groups',coalesce((SELECT jsonb_agg(to_jsonb(g) ORDER BY currency) FROM groups g),'[]'::jsonb),
 'occurrences',coalesce((SELECT jsonb_agg(to_jsonb(v) ORDER BY due,schedule_id) FROM
  (SELECT schedule_id::text,name,currency,due::text,expected::text,paid::text,remaining::text,overlap::text,unfunded::text FROM actual_values WHERE due_now ORDER BY due,schedule_id LIMIT 20) v),'[]'::jsonb),
 'linked_occurrences',coalesce((SELECT jsonb_object_agg(o.schedule_id::text||':'||o.due::text,jsonb_build_object('currency',o.currency,'remaining',o.remaining::text,'due',o.due::text)) FROM occurrences o WHERE o.direction='expense' AND EXISTS(SELECT 1 FROM configured_links l WHERE l.schedule_id=o.schedule_id AND l.due_date=o.due)),'{}'::jsonb),
 'occurrence_count',(SELECT count(*) FROM actual_values WHERE due_now),
 'month_start',(SELECT month_start::text FROM parameters),'month_end',(SELECT month_end::text FROM parameters)
) AS data
SQL, [$owner->id, $utcDate, $horizon, $start, $end, json_encode($requirements, JSON_THROW_ON_ERROR), json_encode($links, JSON_THROW_ON_ERROR)]);

        $result = json_decode($row->data, true, flags: JSON_THROW_ON_ERROR);
        $usedReservations = [];
        $usedOccurrences = [];
        $nowOverlap = [];
        $monthlyOverlap = [];
        foreach ($overlaps as $link) {
            $key = $link['schedule_id'].':'.$link['due_date'];
            $occurrence = $result['linked_occurrences'][$key] ?? null;
            if ($occurrence === null) {
                continue;
            }
            $reservation = $reservations[$link['reservation_id']]['minor'] ?? '0';
            $capacity = bcsub(PlanningInput::minor($reservation), $usedReservations[$link['reservation_id']] ?? '0', 0);
            $remaining = bcsub($occurrence['remaining'], $usedOccurrences[$key] ?? '0', 0);
            $grant = $link['minor'];
            foreach ([$capacity, $remaining] as $limit) {
                if (bccomp($grant, $limit, 0) > 0) {
                    $grant = $limit;
                }
            }
            if (bccomp($grant, '0', 0) <= 0) {
                continue;
            }
            $usedReservations[$link['reservation_id']] = bcadd($usedReservations[$link['reservation_id']] ?? '0', $grant, 0);
            $usedOccurrences[$key] = bcadd($usedOccurrences[$key] ?? '0', $grant, 0);
            $currency = $occurrence['currency'];
            if ($occurrence['due'] <= $horizon) {
                $nowOverlap[$currency] = bcadd($nowOverlap[$currency] ?? '0', $grant, 0);
            }
            if ($occurrence['due'] >= $start && $occurrence['due'] <= $end) {
                $monthlyOverlap[$currency] = bcadd($monthlyOverlap[$currency] ?? '0', $grant, 0);
            }
        }
        foreach ($result['groups'] as &$group) {
            $group['overlap'] = $nowOverlap[$group['currency']] ?? '0';
            $group['unfunded'] = bcsub($group['required'], $group['overlap'], 0);
            $group['monthly_overlap'] = $monthlyOverlap[$group['currency']] ?? '0';
        }
        unset($group);
        foreach ($result['occurrences'] as &$occurrence) {
            $key = $occurrence['schedule_id'].':'.$occurrence['due'];
            $occurrence['overlap'] = $usedOccurrences[$key] ?? '0';
            $occurrence['unfunded'] = bcsub($occurrence['remaining'], $occurrence['overlap'], 0);
        }
        unset($occurrence);
        unset($result['linked_occurrences']);

        return $result;
    }
}
