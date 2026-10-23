<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Observations\ObservationTime;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class PriceObservationService
{
    /** @param array<string,mixed> $input */
    public function record(User $owner, string $key, array $input): stdClass
    {
        return $this->recordTime($owner, $key, $input, null);
    }

    /** @param array<string,mixed> $input */
    public function recordProvider(User $owner, string $key, array $input, ObservationTime $time): stdClass
    {
        return $this->recordTime($owner, $key, $input, $time);
    }

    /** @param array<string,mixed> $input */
    private function recordTime(User $owner, string $key, array $input, ?ObservationTime $time): stdClass
    {
        $request = $time === null ? $input : [...$input, 'provider_time' => $time->columns()];

        return DB::transaction(function () use ($owner, $key, $input, $time, $request): stdClass {
            LedgerInput::lock($owner);
            if ($time !== null) {
                LedgerInput::instant($time->receivedAt, 'received_at');
            }
            PortfolioInput::only($input, ['portfolio_account_id', 'asset_id', 'unit_price', 'source', 'observed_at', 'effective_at', 'supersedes_id', 'reason', 'unit_basis_confirmed', 'context']);
            if (($input['unit_basis_confirmed'] ?? null) !== '1') {
                LedgerInput::fail('unit_basis_confirmed', 'Confirm the raw price uses units after all retained splits through its effective UTC business date.');
            }
            // Recover the original request even after a later split changes the derived unit context.
            if ($cached = LedgerInput::cached('portfolio_prices', $owner, $key, $request)) {
                return $cached;
            }
            $p = app(PortfolioRead::class)->account($owner, LedgerInput::identifier($input['portfolio_account_id'] ?? null), true);
            if ($p->archived) {
                LedgerInput::fail('portfolio_account_id', 'An archived account cannot receive new observations.');
            }
            if (! is_string($input['context'] ?? null) || ! hash_equals($this->context($owner, (string) $p->id), $input['context'])) {
                LedgerInput::fail('unit_basis_confirmed', 'The account history changed after this price form was opened. Reload the form, review corporate actions and confirm the raw unit basis again. No price was recorded.');
            }
            $asset = LedgerInput::owned('portfolio_assets', $owner, LedgerInput::identifier($input['asset_id'] ?? null));
            if ($asset->currency !== $p->currency || $asset->kind === 'manual_investment') {
                LedgerInput::fail('asset_id', 'Choose a unitized asset in this account currency. Whole-position manual values retain their own dated statement policy.');
            }
            $prior = empty($input['supersedes_id']) ? null : LedgerInput::owned('portfolio_prices', $owner, LedgerInput::identifier($input['supersedes_id']));
            if ($prior !== null && ((string) $prior->portfolio_account_id !== (string) $p->id || $prior->asset_family !== $asset->family_reference)) {
                LedgerInput::fail('supersedes_id', 'A correction retains the owned account and asset family.');
            }
            if ($prior !== null && DB::table('portfolio_prices')->where('supersedes_id', $prior->id)->exists()) {
                LedgerInput::fail('supersedes_id', 'This price has a newer version. Open that version before correcting.');
            }
            $effective = $time === null ? LedgerInput::instant($input['effective_at'] ?? null, 'effective_at') : $time->eligibleAt;
            $sourceDate = $time->sourceDate ?? substr($effective, 0, 10);
            $data = ['owner_id' => $owner->id, 'portfolio_account_id' => $p->id, 'currency' => $p->currency,
                'asset_id' => $asset->id, 'asset_family' => $asset->family_reference, 'asset_kind' => $asset->kind,
                'reference' => (string) Str::ulid(), 'series_id' => $prior->series_id ?? (string) Str::ulid(),
                'version' => $prior === null ? 1 : $prior->version + 1, 'supersedes_id' => $prior?->id,
                'unit_price' => PortfolioInput::decimal($input['unit_price'] ?? null, 'unit_price'),
                'observed_at' => $time === null ? LedgerInput::instant($input['observed_at'] ?? null, 'observed_at') : $time->sourceInstant, 'effective_at' => $time === null ? $effective : $time->sourceInstant,
                'source' => LedgerInput::text($input['source'] ?? null, 'source'), 'reason' => LedgerInput::text($input['reason'] ?? null, 'reason'),
                'split_basis' => self::splitBasis($owner, (string) $p->id, $asset->family_reference, $sourceDate),
                'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($request)];
            if ($time !== null) {
                $data = [...$data, ...$time->columns()];
            }
            $id = DB::table('portfolio_prices')->insertGetId($data);

            return LedgerInput::owned('portfolio_prices', $owner, (string) $id);
        });
    }

    /** Bind a new admission to the retained history reviewed by the form, without revealing the application key. */
    public function context(User $owner, string $account): string
    {
        app(PortfolioRead::class)->account($owner, $account);
        $versions = [];
        foreach (['investment_events', 'portfolio_openings', 'portfolio_modes'] as $table) {
            $versions[$table] = DB::table($table)->where('owner_id', $owner->id)->where('portfolio_account_id', $account)->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();
        }
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new \LogicException('The existing application encryption key is required for price context confirmation.');
        }

        return hash_hmac('sha256', LedgerInput::hash(['owner' => (string) $owner->id, 'account' => $account, 'versions' => $versions]), $key);
    }

    public static function splitBasis(User $owner, string $account, string $family, string $date): string
    {
        $splits = DB::table('investment_events as e')->where('e.owner_id', $owner->id)->where('e.portfolio_account_id', $account)->where('e.asset_family', $family)
            ->where('e.kind', 'split')->where('e.effective_date', '<=', $date)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('investment_events as r')->whereColumn('r.reverses_id', 'e.id')->where('r.owner_id', $owner->id))
            ->orderBy('e.id')->pluck('e.id')->map(fn ($v): string => (string) $v)->all();

        return LedgerInput::hash($splits);
    }
}
