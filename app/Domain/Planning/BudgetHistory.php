<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class BudgetHistory
{
    public const PREVIEW_SIZE = 10;

    public const PAGE_SIZE = 20;

    private function query(User $owner): Builder
    {
        return DB::table('planning_occurrences as o')->leftJoin('planning_allocations as a', 'a.occurrence_id', '=', 'o.id')
            ->leftJoin('journal_entries as j', 'j.id', '=', 'a.journal_id')->leftJoin('planning_releases as r', 'r.allocation_id', '=', 'a.id')->where('o.owner_id', $owner->id);
    }

    private function period(Builder $query, string $month): Builder
    {
        return $query->whereBetween('o.due_date', [$month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString()]);
    }

    /** @return array<string, stdClass> */
    public function summaries(User $owner, string $month): array
    {
        return $this->period($this->query($owner), $month)->selectRaw("o.id, count(a.id)::text AS allocation_count, coalesce(sum(CASE WHEN j.status='posted' AND r.id IS NULL THEN a.amount_minor::numeric ELSE 0 END),0)::text AS used, coalesce(bool_or(a.id IS NOT NULL AND r.id IS NULL AND j.status<>'posted'),false) AS needs_review")->groupBy('o.id')->get()->keyBy('id')->all();
    }

    private function evidence(Builder $query): Builder
    {
        return $query->whereNotNull('a.id')->select('a.*', 'o.currency', 'j.reference as journal_reference', 'j.status as journal_status', 'j.effective_date as journal_date', 'r.id as release_id', 'r.reason as release_reason');
    }

    /** Bounded preview per occurrence; aggregates above always include all links.
     * @return array<string, list<array<string, mixed>>> */
    public function previews(User $owner, string $month): array
    {
        $ranked = $this->evidence($this->period($this->query($owner), $month))->selectRaw('row_number() over (partition by o.id order by a.id desc) as position');
        $rows = DB::query()->fromSub($ranked, 'history')->where('position', '<=', self::PREVIEW_SIZE)->orderBy('id')->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->occurrence_id][] = $this->serialize($row);
        }

        return $result;
    }

    /** @return array{rows:list<array<string,mixed>>,next_cursor:?string} */
    public function page(User $owner, string $occurrenceId, ?string $before): array
    {
        $query = $this->evidence($this->query($owner)->where('o.id', $occurrenceId));
        if ($before !== null) {
            $query->where('a.id', '<', $before);
        }
        $rows = $query->orderByDesc('a.id')->limit(self::PAGE_SIZE + 1)->get()->all();
        $visible = array_slice($rows, 0, self::PAGE_SIZE);
        $last = $visible[self::PAGE_SIZE - 1] ?? null;

        return ['rows' => array_map(fn (stdClass $row): array => $this->serialize($row), $visible), 'next_cursor' => count($rows) > self::PAGE_SIZE && $last !== null ? (string) $last->id : null];
    }

    /** @return array<string,mixed> */
    private function serialize(stdClass $a): array
    {
        return ['id' => (string) $a->id, 'reference' => $a->reference, 'journal_id' => (string) $a->journal_id, 'journal_reference' => $a->journal_reference,
            'journal_date' => $a->journal_date, 'journal_status' => $a->journal_status, 'released' => $a->release_id !== null, 'release_reason' => $a->release_reason,
            'effective' => $a->release_id === null && $a->journal_status === 'posted', 'amount' => BudgetActivity::money($a->currency, (string) $a->amount_minor)];
    }
}
