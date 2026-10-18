<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class AvailabilitySettings
{
    public function latest(User $owner): ?stdClass
    {
        return DB::table('cash_availability_settings')->where('owner_id', $owner->id)->orderByDesc('version')->first();
    }

    /** @param array<string,mixed> $input */
    public function save(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            LedgerInput::key($key);
            PlanningInput::only($input, ['prior_id', 'currency', 'buffer', 'next_income_date', 'investment_target', 'requirements', 'overlaps', 'investment_goals', 'reason']);
            if ($cached = LedgerInput::cached('cash_availability_settings', $owner, $key, $input)) {
                return $cached;
            }
            $currency = PlanningInput::currency($input['currency'] ?? null);
            $requirements = [];
            foreach ($this->rows($input['requirements'] ?? null, 'requirements') as $entry) {
                PlanningInput::only($entry, ['schedule_id', 'required']);
                $s = LedgerInput::owned('planning_schedules', $owner, LedgerInput::identifier($entry['schedule_id'] ?? null));
                $c = LedgerInput::owned('planning_categories', $owner, (string) $s->category_id);
                if ($c->direction !== 'expense' || isset($requirements[$s->family_reference])) {
                    LedgerInput::fail('requirements', 'Classify each expense family once.');
                }
                $requirements[$s->family_reference] = ['schedule_id' => (string) $s->id, 'family' => $s->family_reference, 'required' => PlanningInput::flag($entry['required'] ?? null, 'requirements')];
            }
            ksort($requirements);
            $investment = [];
            $ids = $input['investment_goals'] ?? null;
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 1000) {
                LedgerInput::fail('investment_goals', 'Choose an explicit list of investment reservation goals.');
            }
            foreach ($ids as $id) {
                $goal = LedgerInput::owned('goal_plans', $owner, LedgerInput::identifier($id));
                if ($goal->supersedes_id !== null || $goal->mode !== 'reserved' || isset($investment[(string) $goal->id])) {
                    LedgerInput::fail('investment_goals', 'Choose each reserved goal root once.');
                }
                $investment[(string) $goal->id] = (string) $goal->id;
            }
            uksort($investment, fn ($a, $b): int => bccomp(PlanningInput::minor((string) $a), PlanningInput::minor((string) $b), 0));
            $overlaps = [];
            $reservationUsed = [];
            $occurrenceUsed = [];
            foreach ($this->rows($input['overlaps'] ?? null, 'overlaps') as $entry) {
                PlanningInput::only($entry, ['reservation_id', 'schedule_id', 'due_date', 'amount']);
                $r = LedgerInput::owned('goal_reservations', $owner, LedgerInput::identifier($entry['reservation_id'] ?? null));
                $g = LedgerInput::owned('goal_plans', $owner, (string) $r->goal_id);
                $s = LedgerInput::owned('planning_schedules', $owner, LedgerInput::identifier($entry['schedule_id'] ?? null));
                $due = PlanningInput::date($entry['due_date'] ?? null, 'overlaps');
                $next = DB::table('planning_schedules')->where('owner_id', $owner->id)->where('supersedes_id', $s->id)->value('cutover_date');
                if ($s->currency !== $g->currency || ! ($requirements[$s->family_reference]['required'] ?? false)
                    || isset($investment[(string) $g->id]) || BudgetCalendar::due($s, substr($due, 0, 7), $next) !== $due
                    || DB::table('goal_releases')->where('reservation_id', $r->id)->exists()) {
                    LedgerInput::fail('overlaps', 'Link an active non-investment reservation to an explicitly required occurrence in its native currency.');
                }
                $minor = PlanningInput::positive(PlanningInput::currency($s->currency), $entry['amount'] ?? null, 'overlaps')->minor;
                $identity = $s->id.':'.$due;
                $reservationUsed[$r->id] = bcadd($reservationUsed[$r->id] ?? '0', $minor, 0);
                $occurrenceUsed[$identity] = bcadd($occurrenceUsed[$identity] ?? '0', $minor, 0);
                $linkKey = $r->id.':'.$identity;
                if (bccomp($reservationUsed[$r->id], PlanningInput::minor((string) $r->amount_minor), 0) > 0 || bccomp($occurrenceUsed[$identity], PlanningInput::minor((string) $s->expected_minor), 0) > 0 || isset($overlaps[$linkKey])) {
                    LedgerInput::fail('overlaps', 'Each reservation and occurrence has finite capacity; remove duplicate or excessive links.');
                }
                $overlaps[$linkKey] = ['reservation_id' => (string) $r->id, 'schedule_id' => (string) $s->id, 'due_date' => $due, 'minor' => $minor, 'currency' => $s->currency];
            }
            uasort($overlaps, fn ($a, $b): int => bccomp(PlanningInput::minor($a['reservation_id']), PlanningInput::minor($b['reservation_id']), 0) ?: (bccomp(PlanningInput::minor($a['schedule_id']), PlanningInput::minor($b['schedule_id']), 0) ?: strcmp($a['due_date'], $b['due_date'])));
            $payload = ['supersedes_id' => ($input['prior_id'] ?? '') === '' ? null : LedgerInput::identifier($input['prior_id']),
                'currency' => $currency->value, 'buffer_minor' => GoalService::nonnegative($currency, $input['buffer'] ?? null, 'buffer')->minor,
                'next_income_date' => PlanningInput::date($input['next_income_date'] ?? null, 'next_income_date'),
                'investment_target_minor' => GoalService::nonnegative($currency, $input['investment_target'] ?? null, 'investment_target')->minor,
                'requirements' => array_values($requirements), 'overlaps' => array_values($overlaps), 'investment_goals' => array_values($investment),
                'reason' => LedgerInput::text($input['reason'] ?? null, 'reason')];
            $prior = $this->latest($owner);
            if ($payload['supersedes_id'] !== ($prior === null ? null : (string) $prior->id)) {
                LedgerInput::fail('prior_id', 'Settings changed. Recover an existing attempt or reload before starting a new version.');
            }
            if ($payload['next_income_date'] < BudgetCalendar::today()) {
                LedgerInput::fail('next_income_date', 'Confirm an expected income date today or later.');
            }
            $stored = $payload;
            foreach (['requirements', 'overlaps', 'investment_goals'] as $field) {
                $stored[$field] = json_encode($stored[$field], JSON_THROW_ON_ERROR);
            }
            $stored['overlap_links'] = $stored['overlaps'];
            unset($stored['overlaps']);
            $id = DB::table('cash_availability_settings')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'version' => ($prior->version ?? 0) + 1,
                'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($input), ...$stored]);

            return LedgerInput::owned('cash_availability_settings', $owner, (string) $id);
        });
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 1000 || array_filter($value, fn ($row): bool => ! is_array($row)) !== []) {
            LedgerInput::fail($field, 'Use an explicit list of at most 1,000 entries; split a larger plan before saving.');
        }

        return $value;
    }
}
