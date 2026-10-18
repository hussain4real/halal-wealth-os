<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

final class BudgetActivity
{
    public const PAGE_SIZE = 20;

    // IDs/dates constrain journals first; each counter-leg/classification lookup
    // is correlated to that journal. No owner-wide activity list reaches PHP.
    private function economic(User $owner): Builder
    {
        return DB::query()->fromRaw('( '.<<<'SQL'
SELECT j.id,j.owner_id,j.reference,j.kind,j.status,j.effective_date,j.description,root.id AS original_id,root.kind AS direction,
 p.currency_code AS currency,
 (p.counter_minor * CASE WHEN root.kind='income' THEN -1 ELSE 1 END)::text AS minor,
 pc.id AS category_id,pc.family_reference,pc.name AS category_name,pc.normal_budget,pc.emergency_essential,pc.version AS category_version,
 pa.id AS attribution_id,
 EXISTS(SELECT 1 FROM journal_entries cancellation WHERE cancellation.owner_id=root.owner_id AND cancellation.reverses_journal_id=root.id
  AND to_char(cancellation.effective_date,'YYYY-MM')<>to_char(root.effective_date,'YYYY-MM')
  AND (EXISTS(SELECT 1 FROM journal_entries replacement WHERE replacement.owner_id=root.owner_id AND replacement.replaces_journal_id=root.id)
   OR EXISTS(SELECT 1 FROM fx_operation_journals member JOIN fx_operations replacement ON replacement.replaces_id=member.operation_id
    AND replacement.owner_id=member.owner_id WHERE member.owner_id=root.owner_id AND member.journal_id=root.id))
 ) AS legacy_timing
FROM journal_entries j
JOIN journal_entries root ON root.id=coalesce(j.reverses_journal_id,j.id) AND root.owner_id=j.owner_id AND root.kind IN ('income','expense')
JOIN LATERAL (
 SELECT legs.currency_code,sum(legs.amount_minor::numeric) AS counter_minor
 FROM postings legs JOIN accounts a ON a.id=legs.account_id AND a.owner_id=legs.owner_id AND a.system_role=root.kind
 WHERE legs.journal_id=j.id AND legs.owner_id=j.owner_id GROUP BY legs.currency_code
) p ON true
LEFT JOIN LATERAL (
 SELECT candidate.id,candidate.owner_id,candidate.category_id FROM planning_attributions candidate
 WHERE candidate.journal_id=root.id AND candidate.owner_id=root.owner_id
 AND NOT EXISTS(SELECT 1 FROM planning_attributions newer WHERE newer.supersedes_id=candidate.id)
 ORDER BY candidate.id DESC LIMIT 1
) pa ON true
LEFT JOIN planning_categories pc ON pc.id=pa.category_id AND pc.owner_id=pa.owner_id
WHERE j.owner_id=? AND j.status IN ('posted','reversed')
SQL.' ) AS activity', [$owner->id]);
    }

    private function allocated(Builder $query): Builder
    {
        return $query->selectRaw("activity.*, (SELECT coalesce(sum(a.amount_minor::numeric),0)::text FROM planning_allocations a LEFT JOIN planning_releases r ON r.allocation_id=a.id WHERE a.owner_id=activity.owner_id AND a.journal_id=activity.id AND r.id IS NULL AND activity.status='posted') AS allocated_minor");
    }

    private function period(User $owner, string $month): Builder
    {
        PlanningInput::month($month);

        return $this->economic($owner)->whereBetween('effective_date', [$month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString()]);
    }

    /** Full monthly totals are independent of visible rows/cursors.
     * @return list<stdClass> */
    public function groups(User $owner, string $month): array
    {
        return array_values($this->period($owner, $month)->selectRaw('currency,category_id,direction,sum(minor::numeric)::text AS minor,bool_or(legacy_timing) AS legacy_timing')->groupBy('currency', 'category_id', 'direction')->get()->all());
    }

    /** @return array{rows:list<stdClass>,next_cursor:?string} */
    public function activityPage(User $owner, string $month, ?string $before): array
    {
        $query = $this->allocated($this->period($owner, $month));
        $cursor = PlanningInput::activityCursor($before);
        if ($cursor !== null) {
            [$id,$currency] = explode(':', $cursor);
            $query->where(fn (Builder $q): Builder => $q->where('id', '<', $id)->orWhere(fn (Builder $same): Builder => $same->where('id', $id)->where('currency', '>', $currency)));
        }

        return $this->page($query, true);
    }

    /** All dates remain searchable: no implicit prepayment/late-receipt horizon.
     * @return array{rows:list<stdClass>,next_cursor:?string} */
    public function candidates(User $owner, stdClass $rule, stdClass $category, ?string $before, string $search): array
    {
        $query = $this->economic($owner)->where('kind', $category->direction)->where('status', 'posted')->where('currency', $rule->currency)
            ->where(fn (Builder $q): Builder => $q->whereNull('category_id')->orWhere('family_reference', $category->family_reference))
            ->whereNotExists(fn (Builder $q): Builder => $q->selectRaw('1')->from('postings as other')->join('accounts as counter', 'counter.id', '=', 'other.account_id')->whereColumn('other.journal_id', 'activity.id')->whereColumn('counter.system_role', 'activity.direction')->where('other.currency_code', '<>', $rule->currency));
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        if ($search !== '') {
            $literal = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn (Builder $q): Builder => $q->where('description', 'ilike', $literal)->orWhere('reference', 'ilike', $literal));
        }
        $eligible = DB::query()->fromSub($this->allocated($query), 'receipts')->whereRaw('minor::numeric > allocated_minor::numeric');

        return $this->page($eligible);
    }

    /** @return array{rows:list<stdClass>,next_cursor:?string} */
    private function page(Builder $query, bool $composite = false): array
    {
        $rows = $query->orderByDesc('id')->orderBy('currency')->limit(self::PAGE_SIZE + 1)->get()->all();
        $visible = array_slice($rows, 0, self::PAGE_SIZE);
        $last = $visible[self::PAGE_SIZE - 1] ?? null;

        return ['rows' => $visible, 'next_cursor' => count($rows) > self::PAGE_SIZE && $last !== null ? (string) $last->id.($composite ? ':'.$last->currency : '') : null];
    }

    public function selected(User $owner, string $id): stdClass
    {
        $this->receipt($owner, $id);

        return $this->allocated($this->economic($owner)->where('id', $id))->firstOrFail();
    }

    public function receipt(User $owner, string $id): stdClass
    {
        LedgerInput::owned('journal_entries', $owner, $id);
        $rows = $this->economic($owner)->where('id', $id)->where('kind', '<>', 'reversal')->limit(2)->get()->all();
        if (count($rows) !== 1 || bccomp($rows[0]->minor, '0', 0) <= 0) {
            LedgerInput::fail('journal_id', 'Choose one single-currency posted income or expense receipt. Transfers, openings and principal are not budget activity.');
        }
        try {
            Money::fromMinor(Currency::from($rows[0]->currency), $rows[0]->minor);
        } catch (InvalidArgumentException) {
            LedgerInput::fail('journal_id', 'This receipt amount is outside the supported allocation range.');
        }

        return $rows[0];
    }

    /** @return array<string, mixed> */
    public static function money(string $currency, string $minor, ?string $reason = null): array
    {
        try {
            if ($reason !== null) {
                return ['currency' => $currency, 'minor' => null, 'amount' => null, 'available' => false, 'reason' => $reason];
            }
            $money = Money::fromMinor(Currency::from($currency), $minor);

            return [...$money->jsonSerialize(), 'available' => true, 'reason' => null];
        } catch (InvalidArgumentException) {
            return ['currency' => $currency, 'minor' => null, 'amount' => null, 'available' => false, 'reason' => 'out_of_range'];
        }
    }
}
