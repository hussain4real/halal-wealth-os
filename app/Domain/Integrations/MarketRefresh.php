<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PriceObservationService;
use App\Domain\Shared\Observations\ObservationRead;
use App\Jobs\RefreshMarketFeed;
use App\Models\User;
use App\Support\Release\OwnerExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Durable shared request budget and once-only owned financial admission. */
final class MarketRefresh
{
    public const int MAX_ATTEMPTS = 8192;

    public const int MAX_MAPPINGS = 64;

    public const int MAX_ADMISSIONS = 2048;

    public const int OWNER_EXPORT_BYTES = 50331648;

    public function __construct(private PriceProvider $prices, private FxProvider $fx, private int $ownerExportLimit = self::OWNER_EXPORT_BYTES) {}

    /** @param array<string,mixed> $input */
    public function map(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['kind', 'symbol', 'currency', 'mic_code', 'exchange_timezone', 'base', 'quote',
                'portfolio_account_id', 'asset_id', 'context', 'raw_confirmed', 'enabled', 'supersedes_id']);
            if ($cached = LedgerInput::cached('market_mappings', $owner, $key, $input)) {
                return $cached;
            }
            if (! in_array($input['enabled'] ?? null, ['0', '1'], true)) {
                LedgerInput::fail('enabled', 'Choose enabled or disabled for this mapping. Live provider access remains disabled.');
            }
            $prior = empty($input['supersedes_id']) ? null : LedgerInput::owned('market_mappings', $owner, LedgerInput::identifier($input['supersedes_id']));
            $disable = $prior !== null && $input['enabled'] === '0';
            if (DB::table('market_mappings')->where('owner_id', $owner->id)->count() >= self::MAX_MAPPINGS * ($disable ? 2 : 1)) {
                LedgerInput::fail('mapping', 'Retained mapping capacity reached. Existing history remains available.');
            }
            if ($prior !== null && DB::table('market_mappings')->where('supersedes_id', $prior->id)->exists()) {
                LedgerInput::fail('supersedes_id', 'Open the latest retained mapping version.');
            }
            if ($disable) {
                PortfolioInput::only($input, ['enabled', 'supersedes_id']);
                $feed = MarketFeed::from($this->decoded($prior->feed));
                $target = $this->decoded($prior->target);
            } else {
                try {
                    $kind = $input['kind'] ?? null;
                    $feed = $kind === 'price'
                        ? MarketFeed::price($this->text($input, 'symbol'), $this->text($input, 'currency'), $this->text($input, 'mic_code'), $this->text($input, 'exchange_timezone'))
                        : ($kind === 'fx' ? MarketFeed::fx($this->text($input, 'base'), $this->text($input, 'quote')) : throw new ProviderFailure('instrument_identity'));
                } catch (ProviderFailure $error) {
                    LedgerInput::fail('mapping', $error->getMessage());
                }
                $target = $feed->kind === 'price' ? $this->priceTarget($owner, $input, $feed) : ['base' => $feed->identity['base'], 'quote' => $feed->identity['quote']];
            }
            $identity = $this->targetIdentity($target);
            foreach ($this->current($owner) as $mapping) {
                if ((string) $mapping->id !== (string) $prior?->id && $mapping->enabled && $input['enabled'] === '1'
                    && $this->targetIdentity($this->decoded($mapping->target)) === $identity) {
                    LedgerInput::fail('mapping', 'Update or disable the existing mapping for this target.');
                }
            }
            $id = DB::table('market_mappings')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(),
                'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($input), 'request_payload' => $this->json($input),
                'kind' => $feed->kind, 'feed' => $this->json($feed->data()), 'target' => $this->json($target),
                'enabled' => $input['enabled'] === '1', 'supersedes_id' => $prior?->id, 'created_at' => now('UTC')]);
            $this->headroom($owner, $disable);

            return LedgerInput::owned('market_mappings', $owner, (string) $id);
        });
    }

    /** @return list<stdClass> */
    public function current(User $owner): array
    {
        return array_values(DB::table('market_mappings as m')->where('m.owner_id', $owner->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('market_mappings as s')->whereColumn('s.supersedes_id', 'm.id'))
            ->orderByDesc('m.id')->get()->all());
    }

    /** Dispatch is recoverable: accepted results fan out again without another request. */
    public function dispatchDue(): int
    {
        if (! TwelveData::mockEnabled()) {
            return 0;
        }
        $count = 0;
        $feeds = DB::table('market_mappings as m')->where('m.enabled', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('market_mappings as s')->whereColumn('s.supersedes_id', 'm.id'))
            ->select('m.feed')->groupBy('m.feed')->orderBy('m.feed')->cursor();
        foreach ($feeds as $row) {
            RefreshMarketFeed::dispatch($this->decoded($row->feed), now('UTC')->toDateString());
            $count++;
        }

        return $count;
    }

    /** @return array<string,mixed> */
    public function status(User $owner, ?string $after = null): array
    {
        $all = $this->current($owner);
        $page = array_slice(array_values(array_filter($all, fn ($row) => $after === null || (int) $row->id < (int) $after)), 0, 21);
        $rows = [];
        foreach (array_slice($page, 0, 20) as $mapping) {
            $feed = MarketFeed::from($this->decoded($mapping->feed));
            $target = $this->decoded($mapping->target);
            $attempt = DB::table('market_attempts')->where('feed_key', $feed->key())->orderByDesc('id')->first();
            $result = $attempt === null ? null : DB::table('market_results')->where('attempt_id', $attempt->id)->first();
            $admission = DB::table('market_admissions')->where('owner_id', $owner->id)->where('mapping_id', $mapping->id)->orderByDesc('id')->first();
            $accepted = DB::table('market_admissions')->where('owner_id', $owner->id)->where('target_key', $this->targetKey($target))->where('outcome', 'accepted')->orderByDesc('id')->first();
            $observation = $accepted === null ? null : DB::table($feed->kind === 'price' ? 'portfolio_prices' : 'fx_observations')
                ->where('owner_id', $owner->id)->where('id', $accepted->price_id ?? $accepted->fx_id)->first();
            $rows[] = ['id' => (string) $mapping->id, 'reference' => $mapping->reference, 'enabled' => (bool) $mapping->enabled,
                'feed' => $feed->data(), 'target' => $target,
                'run' => $attempt === null ? null : ['claimed_at' => LedgerInput::utc($attempt->claimed_at), 'deadline' => LedgerInput::utc($attempt->deadline),
                    'ordinal' => $attempt->ordinal, 'status' => $result->status ?? 'interrupted_or_running', 'code' => $result?->safe_code,
                    'retry_after' => $result->retry_after ?? 0],
                'admission' => $admission === null ? null : ['outcome' => $admission->outcome, 'code' => $admission->safe_code],
                'observation' => $observation === null ? null : [...ObservationRead::evidence($observation),
                    'value' => $feed->kind === 'price' ? $observation->unit_price : $observation->value,
                    'source' => $observation->source, 'stale' => ObservationRead::stale($observation, now('UTC')->format('Y-m-d\TH:i:s\Z'))]];
        }

        return ['mappings' => $rows, 'mapping_count' => count($all), 'next' => count($page) > 20 ? (string) $page[19]->id : null];
    }

    /** @return array<string,mixed> */
    public function refresh(MarketFeed $feed, string $window): array
    {
        if (! TwelveData::mockEnabled()) {
            return ['status' => 'disabled'];
        }
        LedgerInput::date($window);
        $claim = $this->claim($feed, $window);
        if (isset($claim['result'])) {
            return $this->fanOut($claim['result']);
        }
        if (! isset($claim['attempt'])) {
            return $claim;
        }
        $attempt = $claim['attempt'];
        $sample = null;
        $failure = null;
        try {
            $sample = $feed->kind === 'price' ? $this->prices->price($feed) : $this->fx->fx($feed);
        } catch (ProviderFailure $error) {
            $failure = $error;
        }
        $result = DB::transaction(function () use ($attempt, $sample, $failure): stdClass {
            $this->providerLock();
            if ($retained = DB::table('market_results')->where('attempt_id', $attempt->id)->first()) {
                return $retained;
            }
            $known = now('UTC');
            $winner = hash('sha256', $attempt->feed_key.'|'.$attempt->refresh_window);
            $late = $known->greaterThan($attempt->deadline) || DB::table('market_results')->where('winner_key', $winner)->exists();
            $status = $late ? 'late' : ($failure === null ? 'accepted' : ($failure->retryable ? 'retryable' : 'rejected'));
            $data = $status === 'accepted' ? $this->json($sample->data()) : null;
            if ($data !== null && strlen($data) > 4096) {
                $status = 'rejected';
                $data = null;
                $failure = new ProviderFailure('payload_size');
            }
            $id = DB::table('market_results')->insertGetId(['attempt_id' => $attempt->id, 'received_at' => $known,
                'status' => $status, 'safe_code' => $late ? 'late_ignored' : ($failure->reason ?? 'accepted'),
                'retry_after' => min(604800, max(0, $failure->retryAfter ?? 0)), 'sample' => $data,
                'winner_key' => $status === 'accepted' ? $winner : null]);

            return DB::table('market_results')->where('id', $id)->first();
        });

        return $result->status === 'accepted' ? $this->fanOut($result) : ['status' => $result->status, 'code' => $result->safe_code, 'result_id' => (string) $result->id];
    }

    /** @return array<string,mixed> */
    private function claim(MarketFeed $feed, string $window): array
    {
        return DB::transaction(function () use ($feed, $window): array {
            $this->providerLock();
            $now = now('UTC');
            $winner = hash('sha256', $feed->key().'|'.$window);
            if ($result = DB::table('market_results')->where('winner_key', $winner)->first()) {
                return ['result' => $result];
            }
            if (DB::table('market_results')->where('safe_code', 'backoff_exceeds_policy')->exists()) {
                return ['status' => 'blocked', 'code' => 'provider_policy_stop'];
            }
            $cooldown = DB::table('market_results')->where('safe_code', 'rate_limited')
                ->selectRaw("max(received_at + retry_after * interval '1 second') AS until")->value('until');
            if ($cooldown !== null && $now->getTimestamp() < strtotime($cooldown)) {
                return ['status' => 'deferred', 'code' => 'provider_backoff', 'retry_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($cooldown))];
            }
            if ($window !== $now->toDateString()) {
                return ['status' => 'blocked', 'code' => 'window_expired'];
            }
            $attempts = DB::table('market_attempts')->where('feed_key', $feed->key())->where('refresh_window', $window)->orderByDesc('ordinal')->get();
            if ($last = $attempts->first()) {
                $outcome = DB::table('market_results')->where('attempt_id', $last->id)->first();
                if ($outcome !== null && $outcome->status === 'rejected') {
                    return ['status' => 'blocked', 'code' => $outcome->safe_code];
                }
                $delay = $last->ordinal === 1 ? 60 : 300;
                $due = $outcome === null ? strtotime($last->deadline) : strtotime($outcome->received_at) + max($delay, $outcome->retry_after);
                if ($now->getTimestamp() < $due) {
                    return ['status' => 'deferred', 'code' => 'backoff_or_inflight', 'retry_at' => gmdate('Y-m-d\TH:i:s\Z', $due)];
                }
            }
            if ($attempts->count() >= 3) {
                return ['status' => 'blocked', 'code' => 'attempts_exhausted'];
            }
            // Every charged claim reserves one <=4096-byte eventual result, even after a crash.
            if (DB::table('market_attempts')->count() >= self::MAX_ATTEMPTS) {
                return ['status' => 'blocked', 'code' => 'evidence_capacity'];
            }
            foreach ([[60, 8], [86400, 800]] as [$seconds, $limit]) {
                $usage = DB::table('market_attempts')->where('claimed_at', '>', $now->copy()->subSeconds($seconds));
                if ((int) (clone $usage)->sum('credits') >= $limit) {
                    return ['status' => 'deferred', 'code' => 'shared_credit_limit'];
                }
            }
            $id = DB::table('market_attempts')->insertGetId(['provider' => 'twelve_data', 'feed_key' => $feed->key(), 'feed' => $this->json($feed->data()),
                'refresh_window' => $window, 'ordinal' => $attempts->count() + 1, 'credits' => 1, 'claimed_at' => $now, 'deadline' => $now->copy()->addSeconds(20)]);

            return ['attempt' => DB::table('market_attempts')->where('id', $id)->first()];
        });
    }

    /** @return array<string,mixed> */
    private function fanOut(stdClass $result): array
    {
        $sample = MarketSample::from($this->decoded($result->sample));
        $ids = DB::table('market_mappings as m')->where('m.enabled', true)->where('m.feed', $this->json($sample->feed->data()))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('market_mappings as s')->whereColumn('s.supersedes_id', 'm.id'))->orderBy('m.id')->pluck('m.id');
        $count = 0;
        foreach ($ids as $id) {
            $mapping = DB::table('market_mappings')->where('id', $id)->first();
            try {
                $this->admit(User::query()->whereKey($mapping->owner_id)->firstOrFail(), (string) $id, (string) $result->id);
            } catch (ProviderFailure $failure) {
                if ($failure->reason !== 'evidence_capacity') {
                    throw $failure;
                }
            }
            $count++;
        }

        return ['status' => 'accepted', 'result_id' => (string) $result->id, 'mappings' => $count];
    }

    public function admit(User $owner, string $mappingId, string $resultId): ?stdClass
    {
        try {
            return DB::transaction(function () use ($owner, $mappingId, $resultId): ?stdClass {
                LedgerInput::lock($owner);
                $mapping = LedgerInput::owned('market_mappings', $owner, $mappingId);
                $result = DB::table('market_results')->where('id', LedgerInput::identifier($resultId))->where('status', 'accepted')->first();
                abort_if($result === null, 404);
                $sample = MarketSample::from($this->decoded($result->sample));
                abort_unless($sample->feed->key() === MarketFeed::from($this->decoded($mapping->feed))->key(), 404);
                $target = $this->decoded($mapping->target);
                $targetKey = $this->targetKey($target);
                $cached = DB::table('market_admissions')->where('owner_id', $owner->id)->where('target_key', $targetKey)->where('sample_key', $sample->key())->where('outcome', 'accepted')->first();
                if ($cached !== null) {
                    return $cached;
                }
                if (! $mapping->enabled || DB::table('market_mappings')->where('supersedes_id', $mapping->id)->exists() || ! TwelveData::mockEnabled()) {
                    LedgerInput::fail('mapping', 'This mapping is disabled or superseded. Its committed receipts remain available.');
                }
                if (DB::table('market_admissions')->where('owner_id', $owner->id)->count() >= self::MAX_ADMISSIONS) {
                    return null;
                }
                if ($retained = DB::table('market_admissions')->where('owner_id', $owner->id)->where('mapping_id', $mapping->id)->where('result_id', $result->id)->first()) {
                    return $retained;
                }
                $source = 'Twelve Data synthetic fixture; mock only';
                $price = null;
                $fx = null;
                $key = 'provider:'.hash('sha256', $targetKey.'|'.$sample->key());
                if ($sample->feed->kind === 'price') {
                    $context = app(PriceObservationService::class)->context($owner, $target['portfolio_account_id']);
                    $basis = PriceObservationService::splitBasis($owner, $target['portfolio_account_id'], $target['asset_family'], $sample->time->sourceDate);
                    if (! hash_equals($target['context'], $context) || ! hash_equals($target['unit_basis'], $basis)) {
                        LedgerInput::fail('mapping', 'Retained history or raw unit basis changed; review and confirm a new mapping version.');
                    }
                    $price = app(PriceObservationService::class)->recordProvider($owner, $key, ['portfolio_account_id' => $target['portfolio_account_id'],
                        'asset_id' => $target['asset_id'], 'unit_price' => $sample->value, 'source' => $source, 'reason' => 'Approved mock provider refresh',
                        'unit_basis_confirmed' => '1', 'context' => $target['context']], $sample->time);
                } else {
                    $fx = app(FxObservationService::class)->recordProvider($owner, $key, ['base_currency' => $target['base'], 'quote_currency' => $target['quote'],
                        'value' => $sample->value, 'source' => $source, 'observed_at' => $sample->time->sourceInstant, 'effective_at' => $sample->time->sourceInstant], $sample->time);
                }
                $id = DB::table('market_admissions')->insertGetId(['owner_id' => $owner->id, 'mapping_id' => $mapping->id, 'result_id' => $result->id,
                    'target_key' => $targetKey, 'sample_key' => $sample->key(), 'outcome' => 'accepted', 'safe_code' => 'accepted', 'price_id' => $price?->id, 'fx_id' => $fx?->id]);
                $this->headroom($owner);

                return LedgerInput::owned('market_admissions', $owner, (string) $id);
            });
        } catch (ValidationException) {
            return $this->rejection($owner, $mappingId, $resultId);
        }
    }

    private function rejection(User $owner, string $mappingId, string $resultId): ?stdClass
    {
        return DB::transaction(function () use ($owner, $mappingId, $resultId): ?stdClass {
            LedgerInput::lock($owner);
            $mapping = LedgerInput::owned('market_mappings', $owner, $mappingId);
            if ($old = DB::table('market_admissions')->where('owner_id', $owner->id)->where('mapping_id', $mappingId)->where('result_id', $resultId)->first()) {
                return $old;
            }
            if (DB::table('market_admissions')->where('owner_id', $owner->id)->count() >= self::MAX_ADMISSIONS) {
                return null;
            }
            $result = DB::table('market_results')->where('id', $resultId)->first();
            $sample = MarketSample::from($this->decoded($result->sample));
            $id = DB::table('market_admissions')->insertGetId(['owner_id' => $owner->id, 'mapping_id' => $mappingId, 'result_id' => $resultId,
                'target_key' => $this->targetKey($this->decoded($mapping->target)), 'sample_key' => $sample->key(),
                'outcome' => 'rejected', 'safe_code' => 'mapping_or_capacity_changed']);
            // A rejected write may not grow an already exhausted export; roll it back as well.
            if (app(OwnerExport::class)->bytes($owner) > min(self::OWNER_EXPORT_BYTES, $this->ownerExportLimit)) {
                throw new ProviderFailure('evidence_capacity');
            }

            return LedgerInput::owned('market_admissions', $owner, (string) $id);
        });
    }

    private function headroom(User $owner, bool $disable = false): void
    {
        $reserve = $disable ? 4194304 : 0;
        if (app(OwnerExport::class)->bytes($owner) > min(self::OWNER_EXPORT_BYTES + $reserve, $this->ownerExportLimit + $reserve)) {
            LedgerInput::fail('mapping', 'Complete retained export capacity reached. No new observation or mapping was committed.');
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    private function priceTarget(User $owner, array $input, MarketFeed $feed): array
    {
        $account = app(PortfolioRead::class)->account($owner, LedgerInput::identifier($input['portfolio_account_id'] ?? null), true);
        $asset = LedgerInput::owned('portfolio_assets', $owner, LedgerInput::identifier($input['asset_id'] ?? null));
        if ($account->archived || $account->currency !== $feed->identity['currency'] || $asset->currency !== $account->currency || $asset->kind === 'manual_investment') {
            LedgerInput::fail('asset_id', 'Choose a compatible active owned unitized target.');
        }
        $context = $this->text($input, 'context');
        if (($input['raw_confirmed'] ?? null) !== '1' || ! hash_equals(app(PriceObservationService::class)->context($owner, (string) $account->id), $context)) {
            LedgerInput::fail('raw_confirmed', 'Review retained account history and explicitly confirm unadjusted raw price units.');
        }

        return ['portfolio_account_id' => (string) $account->id, 'asset_id' => (string) $asset->id, 'asset_family' => $asset->family_reference,
            'currency' => $asset->currency, 'context' => $context, 'unit_basis' => PriceObservationService::splitBasis($owner, (string) $account->id, $asset->family_reference, now('UTC')->toDateString())];
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $field): string
    {
        if (! is_string($input[$field] ?? null)) {
            LedgerInput::fail($field, 'This field requires an explicit text value.');
        }

        return $input[$field];
    }

    /** @param array<string,mixed> $target */
    private function targetIdentity(array $target): string
    {
        return hash('sha256', $this->json(isset($target['base']) ? ['fx', $target['base'], $target['quote']] : ['price', $target['portfolio_account_id'], $target['asset_family'], $target['currency']]));
    }

    /** @param array<string,mixed> $target */
    private function targetKey(array $target): string
    {
        return hash('sha256', $this->json([$this->targetIdentity($target), $target['unit_basis'] ?? null]));
    }

    private function providerLock(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('hwo:twelve-data',0))");
    }

    /** @return array<string,mixed> */
    private function decoded(string $value): array
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<array-key,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
