<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final class GoalService
{
    /** @param array<string, mixed> $input */
    public function plan(User $owner, string $key, array $input, ?string $priorId = null): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input, $priorId): stdClass {
            LedgerInput::lock($owner);
            PlanningInput::only($input, ['name', 'account_id', 'currency', 'mode', 'target', 'opening', 'policy', 'contribution', 'first_deposit', 'target_date', 'standing_order', 'closed', 'reason']);
            $prior = $priorId === null ? null : LedgerInput::owned('goal_plans', $owner, $priorId);
            $account = app(GoalBacking::class)->account($owner, LedgerInput::identifier($input['account_id'] ?? null), true);
            $currency = PlanningInput::currency($input['currency'] ?? null);
            $mode = $input['mode'] ?? null;
            $policy = $input['policy'] ?? null;
            if (! in_array($mode, ['dedicated', 'reserved'], true) || ! in_array($policy, ['fixed_monthly', 'variable'], true)) {
                LedgerInput::fail('mode', 'Choose one backing mode and a fixed monthly or variable policy.');
            }
            if ($account->currency_code !== $currency || ($prior !== null && ($prior->mode !== $mode || $prior->currency !== $currency->value || (string) $prior->account_id !== (string) $account->id))) {
                LedgerInput::fail('account_id', 'Keep the native currency, account and backing mode for this goal. Close and release it before using another backing source.');
            }
            $payload = ['prior_id' => $priorId, 'account_id' => (string) $account->id, 'currency' => $currency->value, 'name' => LedgerInput::text($input['name'] ?? null, 'name'), 'mode' => $mode,
                'target_minor' => PlanningInput::positive($currency, $input['target'] ?? null, 'target')->minor,
                'opening_minor' => self::nonnegative($currency, $input['opening'] ?? null, 'opening')->minor,
                'policy' => $policy, 'contribution_minor' => self::nonnegative($currency, $input['contribution'] ?? null, 'contribution')->minor,
                'first_deposit' => ($input['first_deposit'] ?? '') === '' ? null : PlanningInput::date($input['first_deposit'], 'first_deposit'),
                'target_date' => ($input['target_date'] ?? '') === '' ? null : PlanningInput::date($input['target_date'], 'target_date'),
                'standing_order' => PlanningInput::flag($input['standing_order'] ?? null, 'standing_order'), 'closed' => PlanningInput::flag($input['closed'] ?? '0', 'closed'),
                'reason' => $prior === null ? null : LedgerInput::text($input['reason'] ?? null, 'reason')];
            if ($cached = $this->cached($owner, 'plan', $key, $payload, 'goal_plans')) {
                return $cached;
            }
            if ($prior !== null) {
                $this->current($owner, $prior);
                if ($prior->closed || $payload['opening_minor'] !== (string) $prior->opening_minor) {
                    LedgerInput::fail('opening', 'Closed goals and their opening backing snapshots cannot be rewritten.');
                }
            } elseif ($payload['closed'] || $account->archived_at !== null) {
                LedgerInput::fail('account_id', 'New goals require an active bank or cash account.');
            }
            $other = DB::table('goal_plans as g')->where('g.owner_id', $owner->id)->where('g.account_id', $account->id)->where('g.closed', false)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_plans as n')->whereColumn('n.supersedes_id', 'g.id'));
            if ($prior !== null) {
                $other->where('g.family_reference', '<>', $prior->family_reference);
            }
            if (! $payload['closed'] && $other->where(fn ($q) => $mode === 'dedicated' ? $q : $q->where('g.mode', 'dedicated'))->exists()) {
                LedgerInput::fail('account_id', 'A dedicated account belongs exclusively to one open goal. Use another account or close the previous goal.');
            }
            $root = $prior === null ? null : $this->root($owner, $prior);
            if ($payload['closed'] && $root !== null && $this->activeReservations($owner, (string) $root->id)->exists()) {
                LedgerInput::fail('closed', 'Release every reservation before closing the goal.');
            }
            if ($prior === null) {
                $cash = app(GoalBacking::class)->cash($account);
                if ($cash['cash'] === null || $cash['reason'] === 'negative_cash' || ($mode === 'dedicated' && $cash['cash'] !== $payload['opening_minor'])) {
                    LedgerInput::fail('opening', 'Opening backing must be known existing cash; dedicated opening must equal the current account balance.');
                }
            }
            $reference = (string) Str::ulid();
            $cutoff = $prior->opening_journal_cutoff ?? DB::table('journal_entries')->where('owner_id', $owner->id)->max('id') ?? 0;
            $row = $this->insert($owner, 'goal_plans', [...array_diff_key($payload, ['prior_id' => true]), 'reference' => $reference,
                'family_reference' => $prior->family_reference ?? $reference, 'version' => ($prior->version ?? 0) + 1, 'supersedes_id' => $priorId, 'opening_journal_cutoff' => $cutoff]);
            if ($prior === null && $mode === 'reserved' && bccomp($payload['opening_minor'], '0', 0) > 0) {
                $this->reserveLocked($owner, $row, $payload['opening_minor'], 'Opening reservation from existing cash');
            }

            return $this->remember($owner, 'plan', $key, $payload, $row);
        });
    }

    public function reserve(User $owner, string $key, string $goalId, mixed $amount, string $reason): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $goalId, $amount, $reason): stdClass {
            LedgerInput::lock($owner);
            $goal = LedgerInput::owned('goal_plans', $owner, $goalId);
            $root = $this->root($owner, $goal);
            app(GoalBacking::class)->account($owner, (string) $root->account_id, true);
            $payload = ['goal_id' => (string) $root->id, 'amount_minor' => PlanningInput::positive(Currency::from($root->currency), $amount)->minor, 'reason' => LedgerInput::text($reason, 'reason')];
            if ($cached = $this->cached($owner, 'reserve', $key, $payload, 'goal_reservations')) {
                return $cached;
            }
            $current = $this->current($owner, $goal);
            if ($current->closed || $root->mode !== 'reserved') {
                LedgerInput::fail('goal', 'Choose an open goal using explicit reservations.');
            }
            $row = $this->reserveLocked($owner, $root, $payload['amount_minor'], $payload['reason']);

            return $this->remember($owner, 'reserve', $key, $payload, $row);
        });
    }

    public function release(User $owner, string $key, string $reservationId, string $reason): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $reservationId, $reason): stdClass {
            LedgerInput::lock($owner);
            $reservation = LedgerInput::owned('goal_reservations', $owner, $reservationId);
            LedgerInput::owned('goal_plans', $owner, (string) $reservation->goal_id);
            $payload = ['reservation_id' => $reservationId, 'reason' => LedgerInput::text($reason, 'reason')];
            if ($cached = $this->cached($owner, 'release', $key, $payload, 'goal_releases')) {
                return $cached;
            }
            if (DB::table('goal_releases')->where('reservation_id', $reservationId)->exists()) {
                LedgerInput::fail('reservation', 'This reservation is already released. Check its retained history.');
            }
            $row = $this->insert($owner, 'goal_releases', $payload);

            return $this->remember($owner, 'release', $key, $payload, $row);
        });
    }

    public function link(User $owner, string $key, string $goalId, string $postingId, string $reason): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $goalId, $postingId, $reason): stdClass {
            LedgerInput::lock($owner);
            $goal = LedgerInput::owned('goal_plans', $owner, $goalId);
            $root = $this->root($owner, $goal);
            $posting = LedgerInput::owned('postings', $owner, $postingId);
            $journal = LedgerInput::owned('journal_entries', $owner, (string) $posting->journal_id);
            if ((string) $posting->account_id !== (string) $root->account_id || $posting->currency_code !== $root->currency || ! in_array($journal->status, ['posted', 'reversed'], true)) {
                LedgerInput::fail('posting_id', 'Choose an existing posted movement in this goal’s own account and currency.');
            }
            $payload = ['goal_id' => (string) $root->id, 'posting_id' => $postingId, 'reason' => LedgerInput::text($reason, 'reason')];
            if ($cached = $this->cached($owner, 'link', $key, $payload, 'goal_funding_links')) {
                return $cached;
            }
            if ($this->current($owner, $goal)->closed || DB::table('goal_funding_links')->where('goal_id', $root->id)->where('posting_id', $postingId)->exists()) {
                LedgerInput::fail('posting_id', 'This goal is closed or the movement is already linked. Check history.');
            }
            $row = $this->insert($owner, 'goal_funding_links', $payload);

            return $this->remember($owner, 'link', $key, $payload, $row);
        });
    }

    public function root(User $owner, stdClass $goal): stdClass
    {
        return DB::table('goal_plans')->where('owner_id', $owner->id)->where('family_reference', $goal->family_reference)->whereNull('supersedes_id')->firstOrFail();
    }

    public function current(User $owner, stdClass $goal): stdClass
    {
        $latest = DB::table('goal_plans')->where('owner_id', $owner->id)->where('family_reference', $goal->family_reference)->orderByDesc('version')->firstOrFail();
        if ((string) $latest->id !== (string) $goal->id) {
            LedgerInput::fail('goal', 'This goal changed. Reload its latest plan before starting a new request.');
        }

        return $latest;
    }

    public static function nonnegative(Currency $currency, mixed $value, string $field): Money
    {
        try {
            $money = Money::fromDecimal($currency, $value);
            if (bccomp($money->minor, '0', 0) < 0) {
                throw new InvalidArgumentException('Use a nonnegative exact amount, including zero.');
            }

            return $money;
        } catch (InvalidArgumentException $error) {
            LedgerInput::fail($field, $error->getMessage());
        }
    }

    private function reserveLocked(User $owner, stdClass $root, string $minor, string $reason): stdClass
    {
        $backing = app(GoalBacking::class);
        $account = $backing->account($owner, (string) $root->account_id, true);
        $cash = $backing->cash($account);
        $minor = PlanningInput::minor($minor);
        if ($account->archived_at !== null || $cash['cash'] === null || bccomp(bcadd($backing->nominal($owner, (string) $account->id), $minor, 0), $cash['cash'], 0) > 0) {
            LedgerInput::fail('amount', 'Not enough known unreserved cash. Refresh balances or release an existing reservation.');
        }

        return $this->insert($owner, 'goal_reservations', ['goal_id' => $root->id, 'account_id' => $root->account_id, 'amount_minor' => $minor, 'reason' => $reason]);
    }

    private function activeReservations(User $owner, string $rootId): Builder
    {
        return DB::table('goal_reservations as r')->where('r.owner_id', $owner->id)->where('r.goal_id', $rootId)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_releases as rel')->whereColumn('rel.reservation_id', 'r.id'));
    }

    /** @param array<string, mixed> $payload */
    private function cached(User $owner, string $operation, string $key, array $payload, string $table): ?stdClass
    {
        LedgerInput::key($key);
        $row = DB::table('goal_requests')->where('owner_id', $owner->id)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if ($row !== null && $row->payload_hash !== LedgerInput::hash($payload)) {
            LedgerInput::fail('idempotency_key', 'This request identity already has different details. Recover the original attempt before starting another.');
        }

        return $row === null ? null : LedgerInput::owned($table, $owner, (string) $row->result_id);
    }

    /** @param array<string, mixed> $data */
    private function insert(User $owner, string $table, array $data): stdClass
    {
        $id = DB::table($table)->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), ...$data]);

        return LedgerInput::owned($table, $owner, (string) $id);
    }

    /** @param array<string, mixed> $payload */
    private function remember(User $owner, string $operation, string $key, array $payload, stdClass $row): stdClass
    {
        DB::table('goal_requests')->insert(['owner_id' => $owner->id, 'operation' => $operation, 'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($payload), 'result_id' => $row->id, 'result_reference' => $row->reference]);

        return $row;
    }
}
