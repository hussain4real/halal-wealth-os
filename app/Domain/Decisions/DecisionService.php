<?php

declare(strict_types=1);

namespace App\Domain\Decisions;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\GoalService;
use App\Domain\Planning\PlanningInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class DecisionService
{
    /** @param array<string,mixed> $input */
    public function plan(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, DecisionInput::FIELDS);
            if ($row = LedgerInput::cached('decision_opportunities', $owner, $key, $input)) {
                return $row;
            }
            $prior = ($input['prior_id'] ?? '') === '' ? null : LedgerInput::owned('decision_opportunities', $owner, LedgerInput::identifier($input['prior_id']));
            if ($prior !== null) {
                $this->current($prior);
            }
            $currency = PlanningInput::currency($input['currency'] ?? null);
            $asset = ($input['asset_id'] ?? '') === '' ? null : LedgerInput::owned('portfolio_assets', $owner, LedgerInput::identifier($input['asset_id']));
            if (($asset !== null && $asset->currency !== $currency->value) || ($prior !== null && ($prior->currency !== $currency->value || ($prior->asset_id !== null && (string) $prior->asset_id !== (string) $asset?->id)))) {
                LedgerInput::fail('asset_id', 'Keep the plan currency and its identified exact asset reference. An unidentified watchlist reference can be identified once.');
            }
            $status = $input['status'] ?? null;
            if (! in_array($status, ['watchlist', 'planned', 'closed'], true) || ($prior === null && $status === 'closed')) {
                LedgerInput::fail('status', 'Start a watchlist or planned opportunity. Close an existing plan only after releasing its reservations.');
            }
            $target = DecisionInput::amount($currency, $input['target'] ?? '', 'target');
            $maximum = DecisionInput::amount($currency, $input['maximum'] ?? '', 'maximum');
            if (($status === 'planned' && $target === null) || ($maximum !== null && $target !== null && bccomp($target, $maximum, 0) > 0)) {
                LedgerInput::fail('target', 'A plan needs a known target within its explicitly entered maximum allocation.');
            }
            $tranches = DecisionInput::tranches($input['tranches'] ?? '[]', $currency, $target);
            $attrs = DecisionInput::attributes($input, $currency);
            if ($status === 'planned') {
                foreach (['rationale', 'risks', 'horizon', 'review_triggers', 'review_on'] as $field) {
                    if ($attrs[$field] === null) {
                        LedgerInput::fail($field, 'Retain this part of the thesis before marking a watchlist entry planned.');
                    }
                }
            }
            if ($prior !== null) {
                $this->remaining($owner, $prior, $tranches, $status);
            }
            $reference = (string) Str::ulid();

            return $this->insert('decision_opportunities', $owner, $key, $input, ['reference' => $reference, 'series_id' => $prior->series_id ?? $reference,
                'version' => $prior === null ? 1 : $prior->version + 1, 'supersedes_id' => $prior?->id,
                'name' => LedgerInput::text($input['name'] ?? null, 'name'), 'status' => $status, 'currency' => $currency->value, 'asset_id' => $asset?->id,
                'target_minor' => $target, 'maximum_minor' => $maximum, 'tranches' => json_encode($tranches, JSON_THROW_ON_ERROR), 'attributes' => json_encode($attrs, JSON_THROW_ON_ERROR)]);
        });
    }

    /** @param array<string,mixed> $input */
    public function reserve(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['opportunity_id', 'tranche_key', 'goal_id', 'amount', 'reason']);
            if ($row = LedgerInput::cached('decision_reservations', $owner, $key, $input)) {
                return $row;
            }
            $p = $this->opportunity($owner, $input);
            $tranche = $this->tranche($p, $input['tranche_key'] ?? null);
            $goal = LedgerInput::owned('goal_plans', $owner, LedgerInput::identifier($input['goal_id'] ?? null));
            if ($goal->currency !== $p->currency || $goal->mode !== 'reserved') {
                LedgerInput::fail('goal_id', 'Choose an open reserved goal in this plan’s native currency. Dedicated emergency protection cannot back a tranche.');
            }
            $amount = PlanningInput::positive(Currency::from($p->currency), $input['amount'] ?? null)->minor;
            $totals = app(DecisionRead::class)->totals($owner, $p->series_id, $tranche['key']);
            if (bccomp(bcadd(bcadd($totals['reserved'], $totals['spent'], 0), $amount, 0), $tranche['amount_minor'], 0) > 0) {
                LedgerInput::fail('amount', 'This tranche already has reserved cash or attributed actual spend. Release or review it before another reservation.');
            }
            $reservation = app(GoalService::class)->reserve($owner, 'decision:'.hash('sha256', $key), (string) $goal->id, $input['amount'], LedgerInput::text($input['reason'] ?? null, 'reason'));

            return $this->insert('decision_reservations', $owner, $key, $input, ['opportunity_id' => $p->id, 'series_id' => $p->series_id, 'tranche_key' => $tranche['key'], 'currency' => $p->currency, 'reservation_id' => $reservation->id]);
        });
    }

    /** @param array<string,mixed> $input */
    public function release(User $owner, string $key, string $id, array $input): stdClass
    {
        LedgerInput::key($key);
        PortfolioInput::only($input, ['reason']);
        $r = LedgerInput::owned('decision_reservations', $owner, LedgerInput::identifier($id));

        return app(GoalService::class)->release($owner, 'decision:'.hash('sha256', $key), (string) $r->reservation_id, LedgerInput::text($input['reason'] ?? null, 'reason'));
    }

    /** @param array<string,mixed> $input */
    public function link(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['opportunity_id', 'tranche_key', 'event_id', 'reason']);
            if ($row = LedgerInput::cached('decision_event_links', $owner, $key, $input)) {
                return $row;
            }
            $p = $this->opportunity($owner, $input);
            $tranche = $this->tranche($p, $input['tranche_key'] ?? null);
            $e = LedgerInput::owned('investment_events', $owner, LedgerInput::identifier($input['event_id'] ?? null));
            if ($p->asset_id === null || (string) $p->asset_id !== (string) $e->asset_id || $e->currency !== $p->currency || ! in_array($e->kind, ['buy', 'ipo_pay'], true) || bccomp(PortfolioInput::integer($e->amount_minor), '0', 0) <= 0 || $e->reverses_id !== null || DB::table('investment_events')->where('reverses_id', $e->id)->exists()) {
                LedgerInput::fail('event_id', 'Choose a current actual purchase or paid subscription for this exact asset reference and native currency. Intent or unconfirmed allotment is not actual spend.');
            }
            if (DB::table('decision_event_links')->where('event_id', $e->id)->exists()) {
                LedgerInput::fail('event_id', 'This actual event is already attributed. Open its retained decision history instead of linking it twice.');
            }

            return $this->insert('decision_event_links', $owner, $key, $input, ['opportunity_id' => $p->id, 'series_id' => $p->series_id, 'tranche_key' => $tranche['key'], 'currency' => $p->currency, 'event_id' => $e->id]);
        });
    }

    /** @param array<string,mixed> $input */
    private function opportunity(User $owner, array $input): stdClass
    {
        $p = LedgerInput::owned('decision_opportunities', $owner, LedgerInput::identifier($input['opportunity_id'] ?? null));
        $this->current($p);

        return $p;
    }

    private function current(stdClass $p): void
    {
        if ($p->status === 'closed' || DB::table('decision_opportunities')->where('supersedes_id', $p->id)->exists()) {
            LedgerInput::fail('opportunity_id', 'This plan changed or closed. Reload its latest thesis before a new action. An exact saved retry remains recoverable.');
        }
    }

    /** @return array{key:string,label:string,condition:string,amount_minor:numeric-string} */
    private function tranche(stdClass $p, mixed $key): array
    {
        foreach (json_decode($p->tranches, true, flags: JSON_THROW_ON_ERROR) as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        LedgerInput::fail('tranche_key', 'Choose a retained tranche in this plan.');
    }

    /** @param list<array{key:string,label:string,condition:string,amount_minor:numeric-string}> $tranches */
    private function remaining(User $owner, stdClass $prior, array $tranches, string $status): void
    {
        $keys = DB::table('decision_reservations')->where('owner_id', $owner->id)->where('series_id', $prior->series_id)->pluck('tranche_key')
            ->merge(DB::table('decision_event_links')->where('owner_id', $owner->id)->where('series_id', $prior->series_id)->pluck('tranche_key'))->unique();
        foreach ($keys as $key) {
            $totals = app(DecisionRead::class)->totals($owner, $prior->series_id, $key);
            $needed = bcadd($totals['reserved'], $totals['spent'], 0);
            $matching = array_values(array_filter($tranches, fn (array $t): bool => $t['key'] === $key));
            if (($needed !== '0' && ($matching === [] || bccomp($matching[0]['amount_minor'], $needed, 0) < 0)) || ($status === 'closed' && $totals['reserved'] !== '0')) {
                LedgerInput::fail('tranches', 'Retain each active tranche and its reserved cash/actual spend. Release reservations before closing or reducing the plan.');
            }
        }
    }

    /** @param array<string,mixed> $input
     * @param array<string,mixed> $data */
    private function insert(string $table, User $owner, string $key, array $input, array $data): stdClass
    {
        $id = DB::table($table)->insertGetId(['owner_id' => $owner->id, 'actor_id' => $owner->id, 'actor_is_admin' => User::query()->findOrFail($owner->id)->is_admin,
            'reference' => (string) Str::ulid(), 'reason' => LedgerInput::text($input['reason'] ?? null, 'reason'), 'recorded_at' => now('UTC'),
            'idempotency_key' => LedgerInput::key($key), 'payload_hash' => LedgerInput::hash($input), ...$data]);

        return LedgerInput::owned($table, $owner, (string) $id);
    }
}
