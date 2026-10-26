<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Imports\StatementImports;
use App\Domain\Integrations\MarketRefresh;
use App\Domain\Portfolio\PortfolioRead;
use App\Models\User;
use App\Support\Release\OwnerExport;
use App\Support\Release\OwnerExportLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Denial-only policy and conservative reservations; native services retain recovery authority. */
final class Operations
{
    public const int MAX_RESERVATIONS = 2048;

    public function __construct(private readonly int $exportLimit = OwnerExport::MAX_BYTES) {}

    /** @return array{paused:bool,generation:string,requests_daily:int,units_daily:int,retention_days:int} */
    public function policy(User $owner, Channel $channel): array
    {
        $row = DB::table('operation_controls')->where('owner_id', $owner->id)->where('channel', $channel->value)->first();

        return ['paused' => (bool) ($row->paused ?? true), 'generation' => (string) ($row->generation ?? 0),
            'requests_daily' => (int) ($row->requests_daily ?? $channel->maximumRequests()),
            'units_daily' => (int) ($row->units_daily ?? $channel->maximumUnits()), 'retention_days' => (int) ($row->retention_days ?? 7)];
    }

    /** @param array<string,mixed> $input */
    public function configure(User $owner, Channel $channel, string $generation, array $input): void
    {
        if (array_diff(array_keys($input), ['paused', 'requests_daily', 'units_daily', 'retention_days']) !== []) {
            throw ValidationException::withMessages(['operations' => 'Additional fields are forbidden.']);
        }
        $data = Validator::make($input, ['paused' => ['required', 'boolean'],
            'requests_daily' => ['required', 'integer', 'between:0,'.$channel->maximumRequests()],
            'units_daily' => ['required', 'integer', 'between:0,'.$channel->maximumUnits()],
            'retention_days' => ['required', 'integer', 'between:1,30']])->validate();
        DB::transaction(function () use ($owner, $channel, $generation, $data): void {
            $this->lock($owner);
            $current = $this->policy($owner, $channel);
            abort_unless($current['generation'] === $generation, 409, 'Operational settings changed. Refresh before resuming or changing limits.');
            $row = DB::table('operation_controls')->where('owner_id', $owner->id)->where('channel', $channel->value);
            if (! $row->exists() && ! (bool) $data['paused']) {
                throw ValidationException::withMessages(['generation' => 'Save initial stopped limits before explicitly resuming.']);
            }
            $values = ['paused' => (bool) $data['paused'], 'requests_daily' => (int) $data['requests_daily'],
                'units_daily' => (int) $data['units_daily'], 'retention_days' => (int) $data['retention_days'],
                'generation' => DB::raw('generation + 1'), 'updated_at' => DB::raw('clock_timestamp()')];
            if ($row->exists()) {
                $row->update($values);
            } else {
                $values['generation'] = 1;
                DB::table('operation_controls')->insert(['owner_id' => $owner->id, 'channel' => $channel->value, ...$values]);
            }
            $this->prune($owner, $channel);
            $this->headroom($owner);
        });
    }

    /** No new row or headroom is needed: absent controls already mean stopped. */
    public function pauseAll(User $owner): void
    {
        DB::transaction(function () use ($owner): void {
            $this->lock($owner);
            DB::table('operation_controls')->where('owner_id', $owner->id)->where('paused', false)
                ->update(['paused' => true, 'generation' => DB::raw('generation + 1'), 'updated_at' => DB::raw('clock_timestamp()')]);
        });
    }

    /** Caller must commit this admission BEFORE any external handoff.
     * No reservation can be recreated as a fresh permit from retained state. */
    public function reserve(User $owner, Channel $channel, string $reference, ?DriveAction $action = null): OperationPermit
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Operational reservation requires the native committing admission transaction.');
        }
        Validator::make(['reference' => $reference], ['reference' => ['required', 'uuid']])->validate();
        if (($channel === Channel::Drive) !== ($action !== null)) {
            throw new \LogicException('Only Drive actions select a fixed HTTP envelope.');
        }
        $this->lock($owner);
        $policy = $this->policy($owner, $channel);
        $this->allowed($policy);
        if (DB::table('operation_reservations')->where('owner_id', $owner->id)->where('channel', $channel->value)->where('reference', $reference)->exists()) {
            throw new OperationBlocked('already_reserved');
        }
        $at = $this->instant();
        $usage = $this->usage($owner, $channel, $at);
        $units = $channel->units($action);
        if ($usage['retained'] >= self::MAX_RESERVATIONS) {
            throw new OperationBlocked('retained_capacity');
        }
        if ($usage['requests_today'] >= $policy['requests_daily']) {
            throw new OperationBlocked('request_budget');
        }
        if ($policy['units_daily'] < $usage['units_today'] + $units) {
            throw new OperationBlocked('unit_budget');
        }
        $secret = bin2hex(random_bytes(32));
        $slots = $action?->calls() ?? 1;
        DB::table('operation_reservations')->insert(['owner_id' => $owner->id, 'channel' => $channel->value,
            'reference' => $reference, 'control_generation' => (int) $policy['generation'], 'units' => $units, 'allowance' => $slots,
            'action' => $action?->value, 'permit_hash' => hash('sha256', $secret), 'recorded_at' => $at->format('Y-m-d\TH:i:s.uP')]);
        $this->headroom($owner);

        $committed = false;
        DB::afterCommit(function () use (&$committed): void {
            $committed = true;
        });

        return new OperationPermit($owner, $channel, $reference, (int) $policy['generation'], $slots, $secret,
            function () use (&$committed): bool {
                return $committed;
            });
    }

    /** Drive calls inside later native transactions cannot erase this root-committed allowance.
     * @template T
     *
     * @param callable(OperationPermit):T $work
     * @return T */
    public function drive(User $owner, DriveAction $action, callable $work): mixed
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Drive operational allowance must commit before native orchestration.');
        }
        $permit = DB::transaction(fn () => $this->reserve($owner, Channel::Drive, (string) Str::uuid(), $action));
        try {
            $result = $work($permit);
            $permit->finish('complete');

            return $result;
        } catch (OperationBlocked $error) {
            $permit->finish($permit->hasSent() ? 'uncertain' : 'held');
            throw $error;
        } catch (Throwable $error) {
            $permit->finish($permit->hasSent() ? 'uncertain' : 'unavailable');
            throw $error;
        }
    }

    /** The applicable OWNER lock stays held through the actual send, never a global network lock.
     * @template T
     *
     * @param callable():T $send
     * @return T */
    public function send(OperationPermit $permit, callable $send): mixed
    {
        $permit->assertCommitted();

        return DB::transaction(function () use ($permit, $send) {
            $this->lock($permit->owner);
            $this->assertCurrent($permit);
            $permit->consume();

            return $send();
        }, 1);
    }

    public function assertCurrent(OperationPermit $permit): void
    {
        $policy = $this->policy($permit->owner, $permit->channel);
        $this->allowed($policy);
        $row = DB::table('operation_reservations')->where('owner_id', $permit->owner->id)
            ->where('channel', $permit->channel->value)->where('reference', $permit->reference)->first();
        if ($row === null || $row->status !== 'pending' || ! hash_equals($row->permit_hash, $permit->hash())
            || (int) $row->control_generation !== $permit->generation || $policy['generation'] !== (string) $permit->generation) {
            throw new OperationBlocked('policy_changed');
        }
    }

    public function finish(OperationPermit $permit, string $outcome, int $elapsedMs): void
    {
        if (! in_array($outcome, ['complete', 'unavailable', 'uncertain', 'held', 'revoked'], true) || $elapsedMs < 0) {
            throw new \LogicException('Only fixed operational outcomes and nonnegative elapsed time may be retained.');
        }
        DB::transaction(function () use ($permit, $outcome, $elapsedMs): void {
            $owner = $permit->owner;
            $this->lock($owner);
            $changed = DB::table('operation_reservations')->where('owner_id', $owner->id)->where('channel', $permit->channel->value)
                ->where('reference', $permit->reference)->where('permit_hash', $permit->hash())->where('status', 'pending')
                ->update(['status' => $outcome, 'finished_at' => $this->instant()->format('Y-m-d\TH:i:s.uP'), 'elapsed_ms' => $elapsedMs]);
            if ($changed === 0) {
                return;
            }
            $bucket = $this->instant()->startOfHour()->format('Y-m-d\TH:i:s.uP');
            $where = ['owner_id' => $owner->id, 'channel' => $permit->channel->value, 'bucket_at' => $bucket];
            $metric = DB::table('operation_metrics')->where($where)->first();
            DB::table('operation_metrics')->updateOrInsert($where, ['attempts' => (int) ($metric->attempts ?? 0) + 1,
                'failed' => (int) ($metric->failed ?? 0) + ($outcome === 'complete' ? 0 : 1),
                'maximum_elapsed_ms' => max((int) ($metric->maximum_elapsed_ms ?? 0), $elapsedMs)]);
            $this->prune($owner, $permit->channel);
        });
    }

    /** Expiry touches only disposable coarse metrics, never usage or native/financial evidence. */
    public function prune(User $owner, Channel $channel): void
    {
        $days = $this->policy($owner, $channel)['retention_days'];
        DB::table('operation_metrics')->where('owner_id', $owner->id)->where('channel', $channel->value)
            ->where('bucket_at', '<', $this->instant()->subDays($days)->format('Y-m-d\TH:i:s.uP'))->delete();
    }

    /** Operator maintenance: only disposable metric buckets can expire. */
    public function pruneExpiredMetrics(): int
    {
        return DB::delete("DELETE FROM operation_metrics m USING operation_controls c WHERE m.owner_id=c.owner_id AND m.channel=c.channel AND m.bucket_at < clock_timestamp()-c.retention_days*interval '24 hours'");
    }

    /** @return array{requests_today:int,units_today:int,retained:int,pending:int,uncertain:int} */
    public function usage(User $owner, Channel $channel, ?CarbonImmutable $at = null): array
    {
        $all = DB::table('operation_reservations')->where('owner_id', $owner->id);
        $day = (clone $all)->where('channel', $channel->value)->where('recorded_at', '>=', ($at ?? $this->instant())->startOfDay()->format('Y-m-d\TH:i:s.uP'));

        return ['requests_today' => $day->count(), 'units_today' => (int) $day->sum('units'),
            'retained' => $all->count(), 'pending' => (clone $all)->where('channel', $channel->value)->where('status', 'pending')->count(),
            'uncertain' => (clone $all)->where('channel', $channel->value)->where('status', 'uncertain')->count()];
    }

    /** @return array<string,mixed> */
    public function page(User $owner): array
    {
        return PortfolioRead::coherent(fn (): array => $this->snapshot($owner));
    }

    /** @return array<string,mixed> */
    private function snapshot(User $owner): array
    {
        $at = $this->instant();
        $channels = [];
        foreach (Channel::cases() as $channel) {
            $policy = $this->policy($owner, $channel);
            $usage = $this->usage($owner, $channel, $at);
            $metrics = DB::table('operation_metrics')->where('owner_id', $owner->id)->where('channel', $channel->value)
                ->where('bucket_at', '>=', $at->subDays($policy['retention_days'])->format('Y-m-d\TH:i:s.uP'));
            $held = config('operations.paused') !== false || $policy['paused'];
            $maximumElapsed = (clone $metrics)->max('maximum_elapsed_ms');
            $exhausted = $usage['requests_today'] >= $policy['requests_daily'] || $policy['units_daily'] < $usage['units_today'] + ($channel === Channel::Copilot ? $channel->units() : 1) || $usage['retained'] >= self::MAX_RESERVATIONS;
            $channels[] = ['channel' => $channel->value, 'label' => $channel->label(), 'unit' => $channel->unit(),
                'policy' => $policy, 'usage' => $usage, 'state' => $held ? 'paused' : ($exhausted ? 'exhausted' : 'within_limits'),
                'maximum_requests' => $channel->maximumRequests(), 'maximum_units' => $channel->maximumUnits(),
                'metrics' => ['attempts' => (int) (clone $metrics)->sum('attempts'), 'failed' => (int) (clone $metrics)->sum('failed'),
                    'maximum_elapsed_ms' => $maximumElapsed === null ? null : (int) $maximumElapsed]];
        }

        return ['global_paused' => config('operations.paused') !== false, 'channels' => $channels,
            'drive_allowances' => array_map(fn (DriveAction $action): array => ['action' => $action->value, 'calls' => $action->calls()], DriveAction::cases()),
            'retained_limit' => self::MAX_RESERVATIONS, 'health' => $this->health($owner),
            'access' => ['copilot' => config('copilot.enabled') === true && config('copilot.answer_enabled') === true ? 'configured_optional_transport' : 'disabled',
                'market' => 'disabled_live_mock_only', 'email' => 'disabled_live_mock_only', 'drive' => 'disabled_live_mock_only']];
    }

    /** Counts only: no source/provider/destination payload crosses this projection.
     * @return array<string,int> */
    private function health(User $owner): array
    {
        $health = ['market_retained_mappings' => DB::table('market_mappings')->where('owner_id', $owner->id)->count(),
            'market_retained_admissions' => DB::table('market_admissions')->where('owner_id', $owner->id)->count(),
            'imports_snapshots' => DB::table('statement_snapshots')->where('owner_id', $owner->id)->count(),
            'imports_receipts' => DB::table('statement_receipts')->where('owner_id', $owner->id)->count(),
            'notification_intents' => DB::table('reminder_channels')->where('owner_id', $owner->id)->count(),
            'notification_claims_without_result' => DB::table('reminder_claims as c')->where('c.owner_id', $owner->id)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('reminder_results as r')->whereColumn('r.claim_id', 'c.id'))->count(),
            'drive_unknown' => DB::table('drive_operations')->where('owner_id', $owner->id)->where('state', 'unknown')->count(),
            'drive_reconnect' => DB::table('drive_connections')->where('owner_id', $owner->id)->where('status', 'reconnect')->count(),
            'market_monitored_mappings' => 0, 'market_stale_observations' => 0, 'market_missing_observations' => 0,
            'market_unadmitted_samples' => 0, 'import_previews_sampled' => 0, 'import_previews_unavailable' => 0,
            'import_rows_ready' => 0, 'import_rows_retained' => 0, 'import_rows_invalid' => 0,
            'import_rows_ambiguous' => 0, 'import_rows_identity_conflict' => 0,
            'email_unclaimed' => 0, 'email_uncertain' => 0, 'email_retryable' => 0, 'email_exhausted' => 0];
        $after = null;
        for ($page = 0; $page < 4; $page++) {
            $market = app(MarketRefresh::class)->status($owner, $after);
            foreach ($market['mappings'] as $mapping) {
                $health['market_monitored_mappings']++;
                $health['market_stale_observations'] += (int) ($mapping['observation']['stale'] ?? false);
                $health['market_missing_observations'] += $mapping['observation'] === null ? 1 : 0;
                $health['market_unadmitted_samples'] += (int) $mapping['unadmitted_sample'];
            }
            $after = $market['next'];
            if ($after === null) {
                break;
            }
        }
        $health['market_summary_truncated'] = $after === null ? 0 : 1;
        foreach (DB::table('statement_snapshots')->where('owner_id', $owner->id)->orderByDesc('id')->limit(3)->pluck('id') as $snapshot) {
            try {
                $preview = app(StatementImports::class)->show($owner, (string) $snapshot);
                $health['import_previews_sampled']++;
                foreach ($preview['rows'] as $row) {
                    $key = 'import_rows_'.$row['resolution_status'];
                    if (array_key_exists($key, $health)) {
                        $health[$key]++;
                    }
                }
            } catch (Throwable) {
                $health['import_previews_unavailable']++;
            }
        }
        foreach (DB::select(<<<'SQL'
SELECT c.id, latest.id AS claim_id, latest.ordinal, r.outcome
FROM reminder_channels c
LEFT JOIN LATERAL (SELECT id,ordinal FROM reminder_claims WHERE channel_id=c.id AND owner_id=c.owner_id ORDER BY ordinal DESC LIMIT 1) latest ON true
LEFT JOIN reminder_results r ON r.claim_id=latest.id AND r.owner_id=c.owner_id
WHERE c.owner_id=? AND c.channel='email'
SQL, [$owner->id]) as $row) {
            if ($row->claim_id === null) {
                $health['email_unclaimed']++;
            } elseif ($row->outcome === null || $row->outcome === 'uncertain') {
                $health['email_uncertain']++;
            } elseif ($row->outcome === 'retryable') {
                $health[$row->ordinal >= 3 ? 'email_exhausted' : 'email_retryable']++;
            }
        }

        return $health;
    }

    /** @param array{paused:bool,generation:string,requests_daily:int,units_daily:int,retention_days:int} $policy */
    private function allowed(array $policy): void
    {
        if (config('operations.paused') !== false || $policy['paused']) {
            throw new OperationBlocked('paused');
        }
    }

    private function lock(User $owner): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['hwo:posting:owner:'.$owner->id]);
    }

    private function headroom(User $owner): void
    {
        try {
            $bytes = app(OwnerExport::class)->bytes($owner);
        } catch (OwnerExportLimit) {
            throw new OperationBlocked('export_capacity');
        }
        // Fixed terminal fields plus bounded metric buckets are reserved before handoff.
        if ($bytes > min(OwnerExport::MAX_BYTES, $this->exportLimit) - 1048576) {
            throw new OperationBlocked('export_capacity');
        }
    }

    private function instant(): CarbonImmutable
    {
        return Date::hasTestNow() ? CarbonImmutable::instance(now('UTC')) : CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS at')->at)->utc();
    }
}
