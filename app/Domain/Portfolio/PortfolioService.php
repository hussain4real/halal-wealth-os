<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Ledger\LedgerInput;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class PortfolioService
{
    public function __construct(private PortfolioRead $read) {}

    /** @param array<string,mixed> $input */
    public function asset(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['prior_id', 'name', 'kind', 'currency', 'identifier', 'market', 'source', 'unsupported_terms', 'reason']);
            if ($row = $this->cached($owner, $key, 'portfolio_assets', $input)) {
                return $row;
            }
            $prior = ($input['prior_id'] ?? '') === '' ? null : LedgerInput::owned('portfolio_assets', $owner, LedgerInput::identifier($input['prior_id']));
            if ($prior !== null && DB::table('portfolio_assets')->where('supersedes_id', $prior->id)->exists()) {
                LedgerInput::fail('prior_id', 'This asset has a newer reference version. Reload before revising.');
            }
            $kind = $input['kind'] ?? null;
            if (! in_array($kind, ['equity', 'unitized_fund', 'manual_investment'], true)) {
                LedgerInput::fail('kind', 'Choose an equity, unitized fund or manually valued investment. Other instrument models are unsupported.');
            }
            $currency = PortfolioInput::currency($input['currency'] ?? null)->value;
            if ($prior !== null && ($prior->currency !== $currency || $prior->kind !== $kind)) {
                LedgerInput::fail('kind', 'A reference family retains its kind and currency. Create another reference for a different instrument.');
            }
            $data = ['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'family_reference' => $prior->family_reference ?? (string) Str::ulid(),
                'version' => $prior === null ? 1 : $prior->version + 1, 'supersedes_id' => $prior?->id, 'name' => LedgerInput::text($input['name'] ?? null, 'name'),
                'kind' => $kind, 'currency' => $currency, 'identifier' => PortfolioInput::optional($input['identifier'] ?? null, 'identifier'),
                'market' => PortfolioInput::optional($input['market'] ?? null, 'market'), 'source' => LedgerInput::text($input['source'] ?? null, 'source'),
                'unsupported_terms' => PortfolioInput::optional($input['unsupported_terms'] ?? null, 'unsupported_terms'), 'reason' => LedgerInput::text($input['reason'] ?? null, 'reason')];

            return $this->record($owner, $key, 'portfolio_assets', $input, $data);
        });
    }

    /** @param array<string,mixed> $input */
    public function link(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['account_id', 'platform_label', 'source']);
            if ($row = $this->cached($owner, $key, 'portfolio_accounts', $input)) {
                return $row;
            }
            $account = LedgerInput::owned('accounts', $owner, LedgerInput::identifier($input['account_id'] ?? null), true);
            if ($account->type !== 'investment' || $account->system_role !== null || $account->archived_at !== null) {
                LedgerInput::fail('account_id', 'Choose an active owned investment ledger account.');
            }
            if (DB::table('portfolio_accounts')->where('account_id', $account->id)->exists()) {
                LedgerInput::fail('account_id', 'This account already has a portfolio workspace. Open it to review its mode.');
            }

            return $this->record($owner, $key, 'portfolio_accounts', $input, ['owner_id' => $owner->id, 'reference' => (string) Str::ulid(),
                'account_id' => $account->id, 'currency' => $account->currency_code, 'configured_date' => now('UTC')->toDateString(),
                'platform_label' => LedgerInput::text($input['platform_label'] ?? null, 'platform_label'), 'source' => LedgerInput::text($input['source'] ?? null, 'source')]);
        });
    }

    /** @param array<string,mixed> $input */
    public function opening(User $owner, string $id, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $id, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['prior_id', 'as_of', 'source', 'reason', 'rows']);
            $payload = ['portfolio_account_id' => $id, ...$input];
            if ($row = $this->cached($owner, $key, 'portfolio_openings', $payload)) {
                return $row;
            }
            $account = $this->active($owner, $id);
            $priorFrames = app(InvestmentReplay::class)->capture($owner, $id, now('UTC')->format('Y-m-d\TH:i:s\Z'))['cash_frames'];
            $prior = $this->read->opening($owner, $id);
            if (($input['prior_id'] ?? '') !== ($prior === null ? '' : (string) $prior->id)) {
                LedgerInput::fail('prior_id', 'The opening statement changed. Reload its current version before correcting.');
            }
            $asOf = LedgerInput::date($input['as_of'] ?? null, 'as_of');
            if ($prior !== null && $asOf !== $prior->as_of) {
                LedgerInput::fail('as_of', 'A correction retains the original opening date. Trades and later valuation updates belong to later phases.');
            }
            $rows = $input['rows'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000) {
                LedgerInput::fail('rows', 'Provide an explicit complete list of at most 1,000 opening rows, including an empty list if appropriate.');
            }
            $currency = PortfolioInput::currency($account->currency);
            $prepared = [];
            $families = [];
            foreach ($rows as $i => $entry) {
                if (! is_array($entry)) {
                    LedgerInput::fail('rows', 'Each position must contain explicit fields.');
                }
                PortfolioInput::only($entry, ['asset_id', 'quantity', 'unit_price', 'manual_value', 'basis_known', 'cost_basis']);
                $asset = LedgerInput::owned('portfolio_assets', $owner, LedgerInput::identifier($entry['asset_id'] ?? null));
                if ($asset->currency !== $account->currency || isset($families[$asset->family_reference])) {
                    LedgerInput::fail('rows', 'Use each asset family once and match the account native currency. Mixed-currency positions require separate accounts.');
                }
                $families[$asset->family_reference] = true;
                $known = $entry['basis_known'] ?? null;
                if (! in_array($known, ['0', '1'], true)) {
                    LedgerInput::fail('rows', 'Choose known or unknown cost basis explicitly for each position.');
                }
                if ($known === '0' && ! in_array($entry['cost_basis'] ?? null, ['', null], true)) {
                    LedgerInput::fail('rows', 'Unknown basis cannot also contain a cash basis. Clear the retained amount.');
                }
                $manual = $asset->kind === 'manual_investment';
                if (($manual && (! in_array($entry['quantity'] ?? null, ['', null], true) || ! in_array($entry['unit_price'] ?? null, ['', null], true)))
                    || (! $manual && ! in_array($entry['manual_value'] ?? null, ['', null], true))) {
                    LedgerInput::fail('rows', 'Use units and an optional unit price for unitized assets; a manual value has no invented units or price.');
                }
                $prepared[] = ['owner_id' => $owner->id, 'asset_id' => $asset->id, 'asset_family' => $asset->family_reference, 'kind' => $asset->kind, 'currency' => $account->currency,
                    'quantity' => $manual ? null : PortfolioInput::decimal($entry['quantity'] ?? null, 'rows.'.$i.'.quantity', true),
                    'unit_price' => $manual || in_array($entry['unit_price'] ?? null, ['', null], true) ? null : PortfolioInput::decimal($entry['unit_price'], 'rows.'.$i.'.unit_price'),
                    'manual_minor' => ! $manual || in_array($entry['manual_value'] ?? null, ['', null], true) ? null : PortfolioInput::money($currency, $entry['manual_value'], 'rows.'.$i.'.manual_value'),
                    'basis_known' => $known === '1', 'basis_minor' => $known === '1' ? PortfolioInput::money($currency, $entry['cost_basis'] ?? null, 'rows.'.$i.'.cost_basis') : null];
            }
            $row = $this->record($owner, $key, 'portfolio_openings', $payload, ['owner_id' => $owner->id, 'portfolio_account_id' => $account->id, 'currency' => $account->currency,
                'reference' => (string) Str::ulid(), 'version' => $prior === null ? 1 : $prior->version + 1, 'supersedes_id' => $prior?->id,
                'as_of' => $asOf, 'row_count' => count($prepared), 'source' => LedgerInput::text($input['source'] ?? null, 'source'), 'reason' => LedgerInput::text($input['reason'] ?? null, 'reason')]);
            foreach ($prepared as $entry) {
                DB::table('portfolio_opening_rows')->insert(['opening_id' => $row->id, ...$entry]);
            }
            app(InvestmentReplay::class)->capture($owner, $id, now('UTC')->format('Y-m-d\TH:i:s\Z'), true, $priorFrames);

            return $row;
        });
    }

    /** @param array<string,mixed> $input */
    public function preview(User $owner, string $id, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $id, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['mode', 'platform_label', 'source', 'aggregate_value', 'aggregate_as_of', 'reason']);
            $p = $this->active($owner, $id);
            $mode = $input['mode'] ?? null;
            if (! in_array($mode, ['aggregate', 'detailed'], true)) {
                LedgerInput::fail('mode', 'Choose detailed holdings or whole-account value.');
            }
            if ($mode === 'detailed' && (! in_array($input['aggregate_value'] ?? null, ['', null], true) || ! in_array($input['aggregate_as_of'] ?? null, ['', null], true))) {
                LedgerInput::fail('aggregate_value', 'Detailed mode must not submit a whole-account value. Clear the retained aggregate fields.');
            }
            $payload = ['mode' => $mode, 'platform_label' => LedgerInput::text($input['platform_label'] ?? null, 'platform_label'),
                'source' => LedgerInput::text($input['source'] ?? null, 'source'), 'reason' => LedgerInput::text($input['reason'] ?? null, 'reason'),
                'aggregate_minor' => $mode === 'aggregate' ? PortfolioInput::money(PortfolioInput::currency($p->currency), $input['aggregate_value'] ?? null, 'aggregate_value', true) : null,
                'aggregate_as_of' => $mode === 'aggregate' ? LedgerInput::date($input['aggregate_as_of'] ?? null, 'aggregate_as_of') : null];
            $state = $this->read->state($owner, $id, now('UTC')->format('Y-m-d\TH:i:s\Z'));
            $before = $state['contribution'];
            $after = $this->read->contribution($state, $payload);
            $difference = $before['value'] === null || $after['value'] === null ? null : bcsub($after['value']['minor'], $before['value']['minor'], 0);
            $pid = DB::table('portfolio_mode_previews')->insertGetId(['owner_id' => $owner->id, 'portfolio_account_id' => $p->id, 'reference' => (string) Str::ulid(),
                'prior_id' => $state['mode_id'], 'business_date' => $state['date'], 'expires_at' => now('UTC')->addMinutes(10),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'baseline_hash' => $this->read->fingerprint($state),
                'evidence' => json_encode(['before' => $before, 'after' => $after, 'difference_minor' => $difference, 'ledger_cash' => $state['ledger_cash'],
                    'opening_id' => $state['opening']['id'] ?? null, 'position_count' => count($state['rows']), 'platform_is_label_only' => true], JSON_THROW_ON_ERROR)]);

            return LedgerInput::owned('portfolio_mode_previews', $owner, (string) $pid);
        });
    }

    public function confirm(User $owner, string $id, string $key, string $previewId, string $acknowledge): stdClass
    {
        return DB::transaction(function () use ($owner, $id, $key, $previewId, $acknowledge): stdClass {
            LedgerInput::lock($owner);
            $input = ['portfolio_account_id' => $id, 'preview_id' => $previewId, 'acknowledge' => $acknowledge];
            if ($row = $this->cached($owner, $key, 'portfolio_modes', $input)) {
                return $row;
            }
            $this->active($owner, $id);
            $preview = LedgerInput::owned('portfolio_mode_previews', $owner, $previewId);
            abort_unless((string) $preview->portfolio_account_id === $id, 404);
            if ($acknowledge !== '1') {
                LedgerInput::fail('acknowledge', 'Review what is included, excluded and unknown before confirming.');
            }
            $state = $this->read->state($owner, $id, now('UTC')->format('Y-m-d\TH:i:s\Z'));
            if ($preview->business_date !== now('UTC')->toDateString() || now('UTC')->greaterThanOrEqualTo($preview->expires_at)
                || (string) ($preview->prior_id ?? '') !== (string) ($state['mode_id'] ?? '') || $preview->baseline_hash !== $this->read->fingerprint($state)
                || DB::table('portfolio_modes')->where('preview_id', $preview->id)->exists()) {
                LedgerInput::fail('preview_id', 'This preview is stale, expired or already confirmed. Review a fresh preview; no mode was changed.');
            }
            $prior = $this->read->mode($owner, $id);
            $payload = json_decode($preview->payload, true, flags: JSON_THROW_ON_ERROR);

            return $this->record($owner, $key, 'portfolio_modes', $input, ['owner_id' => $owner->id, 'portfolio_account_id' => $id, 'reference' => (string) Str::ulid(),
                'version' => $prior === null ? 1 : $prior->version + 1, 'supersedes_id' => $prior?->id, 'preview_id' => $preview->id,
                'effective_date' => $preview->business_date, 'investment_watermark' => $state['investment']['source_event_ids'] === [] ? 0 : end($state['investment']['source_event_ids']), ...$payload]);
        });
    }

    private function active(User $owner, string $id): stdClass
    {
        $p = $this->read->account($owner, $id, true);
        if ($p->archived) {
            LedgerInput::fail('account_id', 'Archived investment accounts retain evidence and cannot receive new portfolio records.');
        }

        return $p;
    }

    /** @param array<string,mixed> $input */
    private function cached(User $owner, string $key, string $table, array $input): ?stdClass
    {
        $r = LedgerInput::cached('portfolio_requests', $owner, $key, ['table' => $table, 'input' => $input]);

        return $r === null ? null : LedgerInput::owned($table, $owner, (string) $r->target_id);
    }

    /** @param array<string,mixed> $input
     * @param array<string,mixed> $data */
    private function record(User $owner, string $key, string $table, array $input, array $data): stdClass
    {
        $id = DB::table($table)->insertGetId($data);
        DB::table('portfolio_requests')->insert(['owner_id' => $owner->id, 'idempotency_key' => $key,
            'payload_hash' => LedgerInput::hash(['table' => $table, 'input' => $input]), 'target_table' => $table, 'target_id' => $id]);

        return LedgerInput::owned($table, $owner, (string) $id);
    }
}
