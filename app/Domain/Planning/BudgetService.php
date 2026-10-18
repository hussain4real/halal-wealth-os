<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class BudgetService
{
    /** @param array<string, mixed> $input */
    public function category(User $owner, string $key, array $input, ?string $priorId = null): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input, $priorId): stdClass {
            LedgerInput::lock($owner);
            PlanningInput::only($input, ['name', 'direction', 'normal_budget', 'emergency_essential', 'reason']);
            $prior = $priorId === null ? null : LedgerInput::owned('planning_categories', $owner, $priorId);
            $direction = LedgerInput::text($input['direction'] ?? null, 'direction');
            if (! in_array($direction, ['income', 'expense'], true) || ($prior !== null && $direction !== $prior->direction)) {
                LedgerInput::fail('direction', 'Keep the category family direction. Create another category for a different activity.');
            }
            $payload = ['name' => LedgerInput::text($input['name'] ?? null, 'name'), 'direction' => $direction,
                'normal_budget' => PlanningInput::flag($input['normal_budget'] ?? null, 'normal_budget'),
                'emergency_essential' => PlanningInput::flag($input['emergency_essential'] ?? null, 'emergency_essential'),
                'prior_id' => $priorId, 'reason' => $prior === null ? null : LedgerInput::text($input['reason'] ?? null, 'reason')];
            if ($cached = $this->cached($owner, 'category', $key, $payload, 'planning_categories')) {
                return $cached;
            }
            $this->latest('planning_categories', $prior);
            $reference = (string) Str::ulid();
            $row = $this->insert($owner, 'planning_categories', ['reference' => $reference, 'family_reference' => $prior->family_reference ?? $reference,
                'version' => ($prior->version ?? 0) + 1, ...array_diff_key($payload, ['prior_id' => true]), 'supersedes_id' => $prior?->id]);

            return $this->remember($owner, 'category', $key, $payload, $row);
        });
    }

    /** @param array<string, mixed> $input */
    public function schedule(User $owner, string $key, array $input, ?string $priorId = null): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input, $priorId): stdClass {
            LedgerInput::lock($owner);
            PlanningInput::only($input, ['category_id', 'name', 'currency', 'amount', 'cadence', 'interval_months', 'first_due_date', 'provision_start_date', 'end_date', 'cutover_date', 'salary', 'standing_order', 'reason']);
            $prior = $priorId === null ? null : LedgerInput::owned('planning_schedules', $owner, $priorId);
            $category = LedgerInput::owned('planning_categories', $owner, LedgerInput::identifier($input['category_id'] ?? null));
            $currency = PlanningInput::currency($input['currency'] ?? null);
            $cadence = LedgerInput::text($input['cadence'] ?? null, 'cadence');
            $interval = match ($cadence) {
                'once' => 0, 'monthly' => 1, 'annual' => 12,
                'term' => is_string($input['interval_months'] ?? null) && preg_match('/^(?:[2-9]|1[0-9]|2[0-4])$/D', $input['interval_months']) ? (int) $input['interval_months'] : -1,
                default => -1,
            };
            if ($interval < 0 || ($cadence !== 'term' && ($input['interval_months'] ?? '') !== '' && (string) $input['interval_months'] !== (string) $interval)) {
                LedgerInput::fail('interval_months', 'Choose once, monthly, annual, or a term between 2 and 24 months.');
            }
            $first = PlanningInput::date($input['first_due_date'] ?? null);
            $end = ($input['end_date'] ?? '') === '' ? null : PlanningInput::date($input['end_date'], 'end_date');
            $provisionStart = in_array($cadence, ['term', 'annual'], true) ? PlanningInput::date($input['provision_start_date'] ?? null, 'provision_start_date') : $first;
            $cutover = $prior === null ? $provisionStart : PlanningInput::date($input['cutover_date'] ?? null, 'cutover_date');
            if (($end !== null && $end < $first) || $first < $provisionStart || $provisionStart < $cutover || ($prior !== null && $prior->supersedes_id !== null && $cutover < $prior->cutover_date)) {
                LedgerInput::fail('cutover_date', 'Keep past planning history. Use a cutover on/after today and a first due date on/after cutover, with an end on/after the first date.');
            }
            $salary = PlanningInput::flag($input['salary'] ?? null, 'salary');
            if ($salary && ($category->direction !== 'income' || $cadence !== 'monthly')) {
                LedgerInput::fail('salary', 'Expected salary needs an income category and monthly cadence.');
            }
            $payload = ['category_id' => (string) $category->id, 'name' => LedgerInput::text($input['name'] ?? null, 'name'), 'currency' => $currency->value,
                'expected_minor' => PlanningInput::positive($currency, $input['amount'] ?? null)->minor, 'cadence' => $cadence, 'interval_months' => $interval,
                'first_due_date' => $first, 'provision_start_date' => $provisionStart, 'end_date' => $end, 'cutover_date' => $cutover, 'salary' => $salary,
                'standing_order' => PlanningInput::flag($input['standing_order'] ?? null, 'standing_order'), 'prior_id' => $priorId,
                'reason' => $prior === null ? null : LedgerInput::text($input['reason'] ?? null, 'reason')];
            if ($cached = $this->cached($owner, 'schedule', $key, $payload, 'planning_schedules')) {
                return $cached;
            }
            $this->latest('planning_schedules', $prior);
            if ($prior !== null && $cutover < BudgetCalendar::today()) {
                LedgerInput::fail('cutover_date', 'Use a cutover on or after today in Qatar.');
            }
            if ($prior !== null && DB::table('planning_allocations as a')->join('planning_occurrences as o', 'o.id', '=', 'a.occurrence_id')
                ->join('journal_entries as j', 'j.id', '=', 'a.journal_id')->where('a.owner_id', $owner->id)->where('o.schedule_id', $prior->id)
                ->where('o.due_date', '>=', $cutover)->where('j.status', 'posted')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('planning_releases as r')->whereColumn('r.allocation_id', 'a.id'))->exists()) {
                LedgerInput::fail('cutover_date', 'This future plan has matched actuals. Release those allocations first or choose a later cutover.');
            }
            $reference = (string) Str::ulid();
            $row = $this->insert($owner, 'planning_schedules', ['reference' => $reference, 'family_reference' => $prior->family_reference ?? $reference,
                'version' => ($prior->version ?? 0) + 1, ...array_diff_key($payload, ['prior_id' => true]), 'supersedes_id' => $prior?->id]);

            return $this->remember($owner, 'schedule', $key, $payload, $row);
        });
    }

    public function attribute(User $owner, string $key, string $journalId, string $categoryId, string $reason): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $journalId, $categoryId, $reason): stdClass {
            LedgerInput::lock($owner);
            $journal = app(BudgetActivity::class)->receipt($owner, $journalId);
            $category = LedgerInput::owned('planning_categories', $owner, $categoryId);
            $payload = ['journal_id' => $journalId, 'category_id' => $categoryId, 'reason' => LedgerInput::text($reason, 'reason')];
            if ($cached = $this->cached($owner, 'attribute', $key, $payload, 'planning_attributions')) {
                return $cached;
            }
            $row = $this->classify($owner, $journal, $category, $payload['reason']);

            return $this->remember($owner, 'attribute', $key, $payload, $row);
        });
    }

    public function allocate(User $owner, string $key, string $scheduleId, string $dueDate, string $journalId, mixed $amount): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $scheduleId, $dueDate, $journalId, $amount): stdClass {
            LedgerInput::lock($owner);
            $rule = LedgerInput::owned('planning_schedules', $owner, $scheduleId);
            $journal = app(BudgetActivity::class)->receipt($owner, $journalId);
            $dueDate = PlanningInput::date($dueDate, 'due_date');
            $minor = PlanningInput::positive(PlanningInput::currency($rule->currency), $amount)->minor;
            $payload = ['schedule_id' => $scheduleId, 'due_date' => $dueDate, 'journal_id' => $journalId, 'amount_minor' => $minor];
            if ($cached = $this->cached($owner, 'allocate', $key, $payload, 'planning_allocations')) {
                return $cached;
            }
            $next = DB::table('planning_schedules')->where('supersedes_id', $rule->id)->value('cutover_date');
            if (BudgetCalendar::due($rule, substr($dueDate, 0, 7), $next) !== $dueDate) {
                LedgerInput::fail('due_date', 'That occurrence is outside the current rule version. Refresh the plan before matching.');
            }
            $category = LedgerInput::owned('planning_categories', $owner, (string) $rule->category_id);
            if ($journal->status !== 'posted' || $journal->currency !== $rule->currency || $journal->direction !== $category->direction) {
                LedgerInput::fail('journal_id', 'Choose a currently posted receipt in the same currency and income/expense direction.');
            }
            if ($journal->family_reference !== null && $journal->family_reference !== $category->family_reference) {
                LedgerInput::fail('journal_id', 'This actual belongs to another category. Explicitly reclassify it first.');
            }
            if ($journal->category_id === null) {
                $this->classify($owner, $journal, $category, 'Explicit schedule matching');
            }
            $occurrence = DB::table('planning_occurrences')->where('schedule_id', $rule->id)->where('due_date', $dueDate)->first();
            if ($occurrence === null) {
                $occurrence = $this->insert($owner, 'planning_occurrences', ['schedule_id' => $rule->id, 'due_date' => $dueDate,
                    'category_id' => $category->id, 'currency' => $rule->currency, 'expected_minor' => $rule->expected_minor]);
            }
            $effective = $this->effective($owner);
            if ((clone $effective)->where('a.occurrence_id', $occurrence->id)->where('a.journal_id', $journal->id)->exists()) {
                LedgerInput::fail('journal_id', 'This receipt already has an allocation here. Release it explicitly before changing that allocation.');
            }
            $occurrenceUsed = (string) (clone $effective)->where('a.occurrence_id', $occurrence->id)->sum(DB::raw('a.amount_minor::numeric'));
            $actualUsed = (string) (clone $effective)->where('a.journal_id', $journal->id)->sum(DB::raw('a.amount_minor::numeric'));
            if (bccomp(bcadd($occurrenceUsed, $minor, 0), PlanningInput::minor((string) $rule->expected_minor), 0) > 0 || bccomp(bcadd($actualUsed, $minor, 0), $journal->minor, 0) > 0) {
                LedgerInput::fail('amount', 'Allocation exceeds the remaining planned amount or actual receipt. Refresh before trying again.');
            }
            $row = $this->insert($owner, 'planning_allocations', ['occurrence_id' => $occurrence->id, 'journal_id' => $journal->id, 'amount_minor' => $minor]);

            return $this->remember($owner, 'allocate', $key, $payload, $row);
        });
    }

    public function release(User $owner, string $key, string $allocationId, string $reason): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $allocationId, $reason): stdClass {
            LedgerInput::lock($owner);
            $allocation = LedgerInput::owned('planning_allocations', $owner, $allocationId);
            $payload = ['allocation_id' => $allocationId, 'reason' => LedgerInput::text($reason, 'reason')];
            if ($cached = $this->cached($owner, 'release', $key, $payload, 'planning_releases')) {
                return $cached;
            }
            if (DB::table('planning_releases')->where('allocation_id', $allocation->id)->exists()) {
                LedgerInput::fail('allocation', 'This allocation is already released. Its evidence remains in history.');
            }
            $row = $this->insert($owner, 'planning_releases', ['allocation_id' => $allocation->id, 'reason' => $payload['reason']]);

            return $this->remember($owner, 'release', $key, $payload, $row);
        });
    }

    /** @return Builder */
    public function effective(User $owner)
    {
        return DB::table('planning_allocations as a')->join('journal_entries as j', 'j.id', '=', 'a.journal_id')
            ->where('a.owner_id', $owner->id)->where('j.status', 'posted')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('planning_releases as r')->whereColumn('r.allocation_id', 'a.id'));
    }

    private function classify(User $owner, stdClass $journal, stdClass $category, string $reason): stdClass
    {
        if ($category->direction !== $journal->direction) {
            LedgerInput::fail('category_id', 'Keep the actual income/expense direction.');
        }
        $prior = DB::table('planning_attributions as a')->where('a.journal_id', $journal->id)->where('a.owner_id', $owner->id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('planning_attributions as n')->whereColumn('n.supersedes_id', 'a.id'))->first();
        if ($prior !== null) {
            $old = LedgerInput::owned('planning_categories', $owner, (string) $prior->category_id);
            if ($old->family_reference !== $category->family_reference && $this->effective($owner)->where('a.journal_id', $journal->id)->exists()) {
                LedgerInput::fail('category_id', 'Release effective allocations before changing category family.');
            }
        }

        return $this->insert($owner, 'planning_attributions', ['journal_id' => $journal->id, 'category_id' => $category->id, 'reason' => $reason, 'supersedes_id' => $prior?->id]);
    }

    private function latest(string $table, ?stdClass $prior): void
    {
        if ($prior !== null && DB::table($table)->where('supersedes_id', $prior->id)->exists()) {
            LedgerInput::fail('reason', 'This version has already been revised. Refresh and use its latest version.');
        }
    }

    /** @param array<string, mixed> $values */
    private function insert(User $owner, string $table, array $values): stdClass
    {
        $id = DB::table($table)->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), ...$values]);

        return LedgerInput::owned($table, $owner, (string) $id);
    }

    /** @param array<string, mixed> $payload */
    private function cached(User $owner, string $operation, string $key, array $payload, string $table): ?stdClass
    {
        LedgerInput::key($key);
        $request = DB::table('planning_requests')->where('owner_id', $owner->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if ($request === null) {
            return null;
        }
        if ($request->payload_hash !== LedgerInput::hash($payload)) {
            LedgerInput::fail('idempotency_key', 'This request identity has different details. Check saved planning history.');
        }

        return LedgerInput::owned($table, $owner, (string) $request->result_id);
    }

    /** @param array<string, mixed> $payload */
    private function remember(User $owner, string $operation, string $key, array $payload, stdClass $row): stdClass
    {
        DB::table('planning_requests')->insert(['owner_id' => $owner->id, 'operation' => $operation, 'idempotency_key' => $key,
            'payload_hash' => LedgerInput::hash($payload), 'result_id' => $row->id, 'result_reference' => $row->reference]);

        return $row;
    }
}
