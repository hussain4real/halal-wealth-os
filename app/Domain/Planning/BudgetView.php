<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final class BudgetView
{
    /** @return array{rules: list<array<string, mixed>>, categories: list<array<string, mixed>>, ...} */
    public function month(User $owner, string $month, ?string $activityBefore = null): array
    {
        PlanningInput::month($month);

        return DB::transaction(function () use ($owner, $month, $activityBefore): array {
            // All app posting/planning writers share this owner boundary.
            LedgerInput::lock($owner);
            $categories = DB::table('planning_categories')->where('owner_id', $owner->id)->orderBy('id')->get()->keyBy('id')->all();
            $rules = DB::table('planning_schedules')->where('owner_id', $owner->id)->orderBy('id')->get();
            $next = [];
            foreach ($rules as $rule) {
                if ($rule->supersedes_id !== null) {
                    $next[(string) $rule->supersedes_id] = $rule->cutover_date;
                }
            }
            $stored = DB::table('planning_occurrences')->where('owner_id', $owner->id)->whereBetween('due_date', [$month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString()])->get();
            $history = app(BudgetHistory::class);
            $summaries = $history->summaries($owner, $month);
            $previews = $history->previews($owner, $month);
            $occurrences = [];
            foreach ($rules as $rule) {
                $due = BudgetCalendar::due($rule, $month, $next[(string) $rule->id] ?? null);
                if ($due !== null) {
                    $row = $stored->first(fn (stdClass $o): bool => $o->schedule_id === $rule->id && $o->due_date === $due);
                    $occurrences[$rule->id.':'.$due] = $this->occurrence($rule, $categories[$rule->category_id], $due, $row, $row === null ? [] : ($previews[(string) $row->id] ?? []), $row === null ? null : ($summaries[$row->id] ?? null), false);
                }
            }
            foreach ($stored as $row) {
                $key = $row->schedule_id.':'.$row->due_date;
                if (! isset($occurrences[$key])) {
                    $rule = $rules->firstWhere('id', $row->schedule_id);
                    $occurrences[$key] = $this->occurrence($rule, $categories[$row->category_id], $row->due_date, $row, $previews[(string) $row->id] ?? [], $summaries[$row->id] ?? null, true);
                }
            }
            uasort($occurrences, fn (array $a, array $b): int => [$a['due_date'], $a['schedule_id']] <=> [$b['due_date'], $b['schedule_id']]);
            $activityService = app(BudgetActivity::class);
            $activity = $activityService->groups($owner, $month);
            $activityPage = $activityService->activityPage($owner, $month, $activityBefore);
            $buckets = [];
            $groups = [];
            foreach ($occurrences as $o) {
                if ($o['superseded']) {
                    continue;
                }
                $cat = $categories[(int) $o['category']['id']];
                $currency = $o['expected']['currency'];
                $buckets[$currency] ??= [];
                $this->group($groups, $cat, $currency, 'planned', $o['expected']['minor']);
                if ($cat->normal_budget) {
                    $this->add($buckets, $currency, 'planned_'.$cat->direction, $o['expected']['minor']);
                }
            }
            $monthEnd = CarbonImmutable::parse($month.'-01', BudgetCalendar::ZONE)->endOfMonth()->toDateString();
            $provisions = [];
            foreach ($rules as $rule) {
                if ($rule->provision_start_date > $monthEnd || ($rule->end_date !== null && $rule->end_date < $monthEnd)
                    || isset($next[(string) $rule->id]) && $next[(string) $rule->id] <= $monthEnd || $rule->interval_months === 0) {
                    continue;
                }
                $minor = BudgetCalendar::provision($rule);
                $cat = $categories[$rule->category_id];
                if ($cat->direction === 'expense' && $cat->emergency_essential) {
                    $this->add($buckets, $rule->currency, 'essential_monthly', $minor);
                }
                if ($rule->interval_months > 1) {
                    $provisions[] = ['schedule_id' => (string) $rule->id, 'name' => $rule->name, 'months' => (string) $rule->interval_months,
                        'amount' => BudgetActivity::money($rule->currency, $minor), 'essential' => $cat->emergency_essential];
                }
            }
            $activityRows = [];
            foreach ($activity as $row) {
                $buckets[$row->currency] ??= [];
                $reason = $row->legacy_timing ? 'legacy_correction_timing' : null;
                if ($row->category_id !== null) {
                    $cat = $categories[$row->category_id];
                    $this->group($groups, $cat, $row->currency, 'actual', $row->minor, $reason);
                    if ($cat->normal_budget) {
                        $this->add($buckets, $row->currency, 'actual_'.$row->direction, $row->minor, $reason);
                    }
                } else {
                    $this->add($buckets, $row->currency, 'unclassified_'.$row->direction, $row->minor, $reason);
                }

            }
            foreach ($activityPage['rows'] as $row) {
                $activityRows[] = [...$this->actual($row), 'period_value' => BudgetActivity::money($row->currency, $row->minor, $row->legacy_timing ? 'legacy_correction_timing' : null)];
            }
            $totals = [];
            foreach ($buckets as $currency => $values) {
                $result = ['currency' => $currency];
                foreach (['planned_income', 'planned_expense', 'actual_income', 'actual_expense', 'unclassified_income', 'unclassified_expense', 'essential_monthly'] as $field) {
                    $result[$field] = BudgetActivity::money($currency, $values[$field]['minor'] ?? '0', $values[$field]['reason'] ?? null);
                }
                $totals[] = $result;
            }
            $comparisons = [];
            foreach ($groups as $group) {
                $currency = $group['currency'];
                $planned = $group['planned']['minor'] ?? '0';
                $actual = $group['actual']['minor'] ?? '0';
                $reason = $group['actual']['reason'] ?? null;
                $plannedValue = BudgetActivity::money($currency, $planned);
                $actualValue = BudgetActivity::money($currency, $actual, $reason);
                $varianceReason = $reason ?? (! $plannedValue['available'] || ! $actualValue['available'] ? 'out_of_range' : null);
                $comparisons[] = ['family' => $group['family'], 'name' => $group['name'], 'direction' => $group['direction'],
                    'planned' => BudgetActivity::money($currency, $planned), 'actual' => BudgetActivity::money($currency, $actual, $reason),
                    'variance' => BudgetActivity::money($currency, bcsub($actual, $planned, 0), $varianceReason)];
            }
            $date = CarbonImmutable::parse($month.'-01', BudgetCalendar::ZONE);

            return ['month' => $month, 'today' => BudgetCalendar::today(), 'previous_month' => $month === '0001-01' ? null : $date->subMonth()->format('Y-m'), 'next_month' => $month === '9998-12' ? null : $date->addMonth()->format('Y-m'),
                'categories' => array_values(array_map(fn (stdClass $c): array => $this->category($c), $categories)),
                'rules' => array_values($rules->map(fn (stdClass $s): array => ['id' => (string) $s->id, 'reference' => $s->reference, 'family' => $s->family_reference,
                    'name' => $s->name, 'currency' => $s->currency, 'cadence' => $s->cadence, 'interval_months' => (string) $s->interval_months,
                    'first_due_date' => $s->first_due_date, 'provision_start_date' => $s->provision_start_date, 'end_date' => $s->end_date, 'cutover_date' => $s->cutover_date,
                    'salary' => (bool) $s->salary, 'standing_order' => (bool) $s->standing_order, 'reason' => $s->reason,
                    'category_id' => (string) $s->category_id, 'supersedes_id' => $s->supersedes_id === null ? null : (string) $s->supersedes_id,
                    'version' => (string) $s->version, 'amount' => BudgetActivity::money($s->currency, (string) $s->expected_minor),
                    'category' => $this->category($categories[$s->category_id]), 'latest' => ! isset($next[(string) $s->id])])->all()),
                'occurrences' => array_values($occurrences), 'totals' => $totals, 'comparisons' => $comparisons, 'provisions' => $provisions, 'activity' => $activityRows,
                'activity_before' => $activityBefore, 'activity_next_cursor' => $activityPage['next_cursor']];
        });
    }

    /** @return array<string,mixed> */
    public function receipts(User $owner, string $scheduleId, ?string $before, string $search, ?string $selected): array
    {
        return DB::transaction(function () use ($owner, $scheduleId, $before, $search, $selected): array {
            LedgerInput::lock($owner);
            $rule = LedgerInput::owned('planning_schedules', $owner, $scheduleId);
            $category = LedgerInput::owned('planning_categories', $owner, (string) $rule->category_id);
            $service = app(BudgetActivity::class);
            // Authorize remembered selection even when it no longer has capacity;
            // same-key uncertain delivery must still be recoverable after reload.
            $chosen = $selected === null ? null : $service->selected($owner, $selected);
            if ($chosen !== null && ($chosen->currency !== $rule->currency || $chosen->direction !== $category->direction)) {
                LedgerInput::fail('selected', 'Choose a receipt in this plan currency and direction.');
            }
            $page = $service->candidates($owner, $rule, $category, $before, $search);

            return ['receipts' => array_map(fn (stdClass $row): array => $this->actual($row), $page['rows']), 'selected' => $chosen === null ? null : $this->actual($chosen), 'next_cursor' => $page['next_cursor']];
        });
    }

    /** @return array<string,mixed> */
    public function history(User $owner, string $occurrenceId, ?string $before): array
    {
        return DB::transaction(function () use ($owner, $occurrenceId, $before): array {
            LedgerInput::lock($owner);
            $stored = LedgerInput::owned('planning_occurrences', $owner, $occurrenceId);
            $rule = LedgerInput::owned('planning_schedules', $owner, (string) $stored->schedule_id);
            $category = LedgerInput::owned('planning_categories', $owner, (string) $stored->category_id);
            $month = substr($stored->due_date, 0, 7);
            $service = app(BudgetHistory::class);
            $summaries = $service->summaries($owner, $month);
            $page = $service->page($owner, $occurrenceId, $before);
            $superseded = DB::table('planning_schedules')->where('owner_id', $owner->id)->where('supersedes_id', $rule->id)->where('cutover_date', '<=', $stored->due_date)->exists();

            return ['month' => $month, 'before' => $before, 'next_cursor' => $page['next_cursor'], 'occurrence' => $this->occurrence($rule, $category, $stored->due_date, $stored, $page['rows'], $summaries[$stored->id] ?? null, $superseded)];
        });
    }

    /** @return array<string, mixed> */
    public function category(stdClass $c): array
    {
        return ['id' => (string) $c->id, 'family' => $c->family_reference, 'version' => (string) $c->version, 'name' => $c->name, 'direction' => $c->direction,
            'normal_budget' => (bool) $c->normal_budget, 'emergency_essential' => (bool) $c->emergency_essential,
            'supersedes_id' => $c->supersedes_id === null ? null : (string) $c->supersedes_id, 'reason' => $c->reason];
    }

    /** @param list<array<string, mixed>> $allocations
     * @return array<string, mixed> */
    private function occurrence(stdClass $rule, stdClass $category, string $due, ?stdClass $stored, array $allocations, ?stdClass $summary, bool $superseded): array
    {
        $history = $allocations;
        $used = PlanningInput::minor($summary->used ?? '0');
        $review = (bool) ($summary->needs_review ?? false);
        $remaining = bcsub(PlanningInput::minor((string) $rule->expected_minor), $used, 0);

        return ['key' => $rule->id.':'.$due, 'id' => $stored === null ? null : (string) $stored->id, 'schedule_id' => (string) $rule->id,
            'name' => $rule->name, 'due_date' => $due, 'expected' => BudgetActivity::money($rule->currency, (string) $rule->expected_minor),
            'matched' => BudgetActivity::money($rule->currency, $used), 'remaining' => BudgetActivity::money($rule->currency, $remaining),
            'category' => $this->category($category), 'status' => $superseded ? 'superseded' : ($remaining === '0' ? 'matched' : ($used === '0' ? 'unpaid' : 'partial')),
            'needs_review' => $review, 'overdue' => ! $superseded && $due < BudgetCalendar::today() && $remaining !== '0',
            'allocation_count' => (string) ($summary->allocation_count ?? '0'), 'history_more' => (int) ($summary->allocation_count ?? 0) > count($history), 'superseded' => $superseded, 'standing_order' => (bool) $rule->standing_order, 'salary' => (bool) $rule->salary, 'allocations' => $history];
    }

    /** @return array<string, mixed> */
    public function actual(stdClass $row): array
    {
        $used = PlanningInput::minor($row->allocated_minor);

        return ['id' => (string) $row->id, 'reference' => $row->reference, 'date' => $row->effective_date, 'kind' => $row->kind,
            'status' => $row->status, 'description' => $row->description, 'direction' => $row->direction,
            'category_id' => $row->category_id === null ? null : (string) $row->category_id, 'category_name' => $row->category_name,
            'family' => $row->family_reference, 'normal_budget' => $row->normal_budget, 'category_version' => $row->category_version === null ? null : (string) $row->category_version,
            'value' => BudgetActivity::money($row->currency, $row->minor), 'allocated' => BudgetActivity::money($row->currency, $used),
            'remaining' => BudgetActivity::money($row->currency, bcsub($row->minor, $used, 0))];
    }

    /** @param array<string, array<string, array{minor: string, reason: ?string}>> $totals */
    private function add(array &$totals, string $currency, string $field, string $minor, ?string $reason = null): void
    {
        $prior = $totals[$currency][$field] ?? ['minor' => '0', 'reason' => null];
        $totals[$currency][$field] = ['minor' => bcadd(PlanningInput::minor($prior['minor']), PlanningInput::minor($minor), 0), 'reason' => $prior['reason'] ?? $reason];
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function group(array &$groups, stdClass $category, string $currency, string $field, string $minor, ?string $reason = null): void
    {
        $key = $category->family_reference.':'.$currency;
        $groups[$key] ??= ['family' => $category->family_reference, 'currency' => $currency, 'name' => $category->name, 'direction' => $category->direction];
        $prior = $groups[$key][$field] ?? ['minor' => '0', 'reason' => null];
        $groups[$key][$field] = ['minor' => bcadd(PlanningInput::minor($prior['minor']), PlanningInput::minor($minor), 0), 'reason' => $prior['reason'] ?? $reason];
    }
}
