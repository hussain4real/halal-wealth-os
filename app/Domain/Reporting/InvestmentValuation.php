<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\TranslatedBookCost;
use App\Domain\Portfolio\ValuationFx;
use App\Domain\Portfolio\ValuationMath;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class InvestmentValuation
{
    public const string POLICY = 'dated-valuation-v1;raw-split-context;transaction-date-EOD-cost;payment-pool-IPO;native-then-FX-row-settlement;covered-signed-allocation;flow-free-EOD-endpoints';

    public function __construct(private NetWorthSnapshot $netWorth, private PortfolioRead $portfolio, private ValuationFx $fx, private TranslatedBookCost $cost) {}

    /** @return array<string,mixed> */
    public function capture(User $owner, Currency $base, string $cutoff, ?string $account = null, ?string $start = null): array
    {
        $cutoff = LedgerInput::instant($cutoff, 'cutoff');
        $start = $start === null ? null : LedgerInput::instant($start, 'start');
        if ($start !== null && ($start >= $cutoff || substr($start, 0, 10) >= substr($cutoff, 0, 10))) {
            LedgerInput::fail('start', 'Choose an earlier UTC business date. Performance compares closing business-date states, with dated price/FX cutoffs.');
        }
        if ($account !== null) {
            $scope = LedgerInput::owned('accounts', $owner, $account);
            if ($scope->system_role !== null || ! in_array($scope->type, ['bank', 'cash', 'investment', 'liability'], true)) {
                LedgerInput::fail('account_id', 'Choose an owned personal bank, cash, investment or liability account. Internal accounting controls are not a valuation scope.');
            }
        }

        return PortfolioRead::coherent(function () use ($owner, $base, $cutoff, $account, $start): array {
            $versions = [];
            foreach (['accounts', 'journal_entries', 'postings', 'portfolio_accounts', 'portfolio_assets', 'portfolio_openings', 'portfolio_opening_rows', 'portfolio_modes', 'investment_events', 'fx_observations', 'portfolio_prices'] as $table) {
                $versions[$table] = DB::table($table)->where('owner_id', $owner->id)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
            }
            $versions['opening_balance_intents'] = DB::table('opening_balance_intents as i')->join('accounts as a', 'a.id', '=', 'i.account_id')->where('a.owner_id', $owner->id)->orderBy('i.id')->select('i.*')->get()->map(fn ($r): array => (array) $r)->all();
            $versions['opening_balance_postings'] = DB::table('opening_balance_postings as p')->join('opening_balance_intents as i', 'i.id', '=', 'p.opening_intent_id')->join('accounts as a', 'a.id', '=', 'i.account_id')->where('a.owner_id', $owner->id)->orderBy('p.opening_intent_id')->select('p.*')->get()->map(fn ($r): array => (array) $r)->all();
            $key = 'investment-valuation:'.LedgerInput::hash(['owner' => (string) $owner->id, 'account' => $account, 'base' => $base->value, 'cutoff' => $cutoff, 'start' => $start, 'policy' => self::POLICY, 'versions' => $versions]);

            // A new retained input produces a new key. Saved request receipts use a different table/identity.
            return Cache::remember($key, 600, function () use ($owner, $base, $cutoff, $account, $start, $key): array {
                $ending = $this->point($owner, $base, $cutoff, $account, $start);
                $beginning = $start === null ? null : $this->point($owner, $base, $start, $account, null);

                return ['policy' => self::POLICY, 'cache_key' => $key, 'owner_id' => (string) $owner->id, 'scope_account_id' => $account,
                    'base_currency' => $base->value, 'cutoff' => $cutoff, 'start' => $start, 'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                    'ending' => $ending, 'beginning' => $beginning,
                    'performance' => $beginning === null ? null : $this->performance($owner, $base, $beginning, $ending, $start, $cutoff)];
            });
        });
    }

    /** @return array<string,mixed> */
    private function point(User $owner, Currency $base, string $cutoff, ?string $account, ?string $start): array
    {
        $snapshot = $this->netWorth->capture($owner, $base, $cutoff, $account);
        $components = [];
        $accounts = [];
        $financialResults = [];
        foreach ($snapshot['components'] as $a) {
            $meta = ['account_id' => $a['account_id'], 'account' => $a['name'], 'currency' => $a['currency']];
            $portfolio = $a['portfolio'] ?? null;
            if ($portfolio !== null) {
                $meta['portfolio_account_id'] = $portfolio['id'];
            }
            if ($portfolio === null || $portfolio['mode'] === 'legacy_ledger') {
                $components[] = $this->component($owner, $base, $cutoff, $meta, 'cash', 'Cash / liability', null, $a['native'], $a['native_reason']);
            } elseif ($portfolio['mode'] === 'aggregate') {
                $components[] = $this->component($owner, $base, $cutoff, $meta, 'aggregate', 'Whole account · no look-through', null, $a['native'], $a['native_reason']);
            } elseif ($portfolio['mode'] !== 'detailed') {
                $components[] = $this->component($owner, $base, $cutoff, $meta, 'unconfirmed', 'Unconfirmed account mode', null, null, 'mode_not_confirmed');
            } else {
                $state = $this->portfolio->state($owner, $portfolio['id'], $cutoff);
                $cost = $this->cost->capture($owner, $state, $base, $start);
                $components[] = $this->component($owner, $base, $cutoff, $meta, 'cash', 'Posted cash', null, $state['ledger_cash'], $state['ledger_reason']);
                foreach ($state['rows'] as $row) {
                    $c = $this->component($owner, $base, $cutoff, $meta, 'holding', $row['name'], $row['geography'] ?? null, $row['valuation']['market_value'], $row['valuation']['reason']);
                    $basis = ValuationMath::money($base, $cost['pools'][$row['asset_family']]['minor'] ?? null);
                    $c['asset_id'] = $row['asset_id'];
                    $c['asset_family'] = $row['asset_family'];
                    $c['quantity'] = $row['quantity'];
                    $c['price'] = $row['valuation']['price'];
                    $c['native_cost'] = $row['cost_basis'];
                    $c['base_cost'] = $basis;
                    $c['native_unrealized_gain'] = $row['valuation']['unrealized_gain'];
                    $c['base_unrealized_gain'] = $basis === null || $c['converted'] === null ? null : ValuationMath::money($base, bcsub($c['converted']['minor'], $basis['minor'], 0));
                    $c['cost_reason'] = $basis === null ? 'historical_basis_or_transaction_FX_unavailable' : null;
                    $c['geography_source'] = $row['geography_source'] ?? null;
                    $components[] = $c;
                }
                if ($state['investment']['subscriptions'] !== []) {
                    $components[] = $this->component($owner, $base, $cutoff, $meta, 'receivable', 'Paid IPO receivables · no shares', null,
                        ValuationMath::money(Currency::from($a['currency']), $state['investment']['receivable_minor']), null);
                }
                $a['cost_and_income'] = $cost;
                $financialResults[] = ['account_id' => $a['account_id'], 'account' => $a['name'], 'currency' => $a['currency'], 'results' => $cost];
            }
            $accounts[] = $a;
        }
        $covered = array_filter($components, fn (array $c): bool => $c['converted'] !== null);
        $subtotal = array_reduce($covered, fn (string $sum, array $c): string => bcadd($sum, $c['converted']['minor'], 0), '0');
        $money = ValuationMath::money($base, $subtotal);
        $complete = count($covered) === count($components) && $money !== null;

        return ['total_reason' => $money === null ? 'aggregate_out_of_range' : null, 'state' => $money === null ? 'unavailable' : ($complete ? 'complete' : (count($covered) > 0 ? 'partial' : 'unavailable')), 'total' => $complete ? $money : null,
            'covered_subtotal' => $covered === [] && $components !== [] ? null : $money, 'covered_minor_exact' => $subtotal,
            'covered_components' => count($covered), 'component_count' => count($components), 'components' => $components, 'accounts' => $accounts,
            'financial_results' => $financialResults,
            'allocation' => $this->allocation($components, $subtotal, $complete), 'net_worth_snapshot' => $snapshot];
    }

    /** @param array<string,mixed> $meta
     * @param array<string,mixed>|null $native
     * @return array<string,mixed> */
    private function component(User $owner, Currency $base, string $cutoff, array $meta, string $kind, string $asset, ?string $geography, ?array $native, ?string $reason): array
    {
        $conversion = $native === null ? ['value' => null, 'rate' => null, 'reason' => $reason] : $this->fx->convert($owner, $native['minor'], Currency::from($meta['currency']), $base, $cutoff, 'reporting');

        return [...$meta, 'kind' => $kind, 'asset' => $asset, 'geography' => $geography ?? 'Unknown / no look-through', 'native' => $native,
            'converted' => $conversion['value'], 'fx' => $conversion['rate'], 'reason' => $reason ?? $conversion['reason']];
    }

    /** @param list<array<string,mixed>> $components
     * @return array<string,mixed> */
    private function allocation(array $components, string $denominator, bool $complete): array
    {
        $dimensions = [];
        foreach (['asset', 'currency', 'geography', 'account'] as $dimension) {
            $groups = [];
            foreach ($components as $c) {
                $label = $dimension === 'asset' && isset($c['asset_family']) ? $c['asset'].' · '.$c['asset_family'] : ($dimension === 'account' ? $c['account_id'] : $c[$dimension]);
                $g = $groups[$label] ?? ['label' => $dimension === 'account' ? $c['account'].' · account '.$c['account_id'] : $c[$dimension], 'minor' => '0', 'known_components' => 0, 'missing_components' => 0];
                if ($c['converted'] === null) {
                    $g['missing_components']++;
                } else {
                    $g['minor'] = bcadd($g['minor'], $c['converted']['minor'], 0);
                    $g['known_components']++;
                }
                $groups[$label] = $g;
            }
            foreach ($groups as &$g) {
                $g['weight'] = ValuationMath::ratio($g['minor'], $denominator);
            }
            unset($g);
            $dimensions[$dimension] = array_values($groups);
        }

        return ['denominator_minor' => $denominator, 'denominator' => 'known covered selected component value in base currency; signed amounts retained',
            'coverage' => $complete ? 'complete' : 'partial', 'percentage_policy' => 'exact signed ratio; four-place display may leave rounding residual; nonpositive denominator suppresses percentages', 'dimensions' => $dimensions];
    }

    /** @param array<string,mixed> $beginning
     * @param array<string,mixed> $ending
     * @return array<string,mixed> */
    private function performance(User $owner, Currency $base, array $beginning, array $ending, string $start, string $cutoff): array
    {
        $ids = array_column($ending['accounts'], 'account_id');
        $flows = [];
        $journals = DB::table('journal_entries as j')->where('j.owner_id', $owner->id)->whereIn('j.status', ['posted', 'reversed'])
            ->where('j.effective_date', '>', substr($start, 0, 10))->where('j.effective_date', '<=', substr($cutoff, 0, 10))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('investment_event_journals as m')->whereColumn('m.journal_id', 'j.id')->where('m.owner_id', $owner->id))->orderBy('j.id')->get();
        foreach ($journals as $j) {
            $legs = DB::table('postings as p')->join('accounts as a', 'a.id', '=', 'p.account_id')->where('p.owner_id', $owner->id)->where('p.journal_id', $j->id)->whereNull('a.system_role')->select(['p.account_id', 'p.amount_minor', 'p.currency_code'])->get();
            $inside = $legs->filter(fn ($p): bool => in_array((string) $p->account_id, $ids, true));
            if ($inside->isEmpty()) {
                continue;
            }
            // Only a proven native transfer wholly within scope is internal. FX groups remain conservative.
            $internal = $j->kind === 'transfer' && $legs->count() === 2 && $inside->count() === 2
                && $legs->pluck('currency_code')->unique()->count() === 1
                && bcadd(PortfolioInput::integer($legs[0]->amount_minor), PortfolioInput::integer($legs[1]->amount_minor), 0) === '0';
            if (! $internal) {
                $flows[] = ['journal_id' => (string) $j->id, 'effective_date' => $j->effective_date, 'kind' => $j->kind,
                    'legs' => $inside->map(fn ($p): array => ['account_id' => (string) $p->account_id, 'minor' => (string) $p->amount_minor, 'currency' => $p->currency_code])->values()->all()];
            }
        }
        $shape = fn (array $point): array => array_map(fn (array $a): array => [$a['account_id'], $a['portfolio']['mode_id'] ?? null, $a['portfolio']['opening']['id'] ?? null], $point['accounts']);
        $reasons = [];
        if ($flows !== []) {
            $reasons[] = 'external_or_unsupported_cash_flows';
        }
        if ($shape($beginning) !== $shape($ending)) {
            $reasons[] = 'account_mode_or_opening_scope_changed';
        }
        if ($beginning['total'] === null || $ending['total'] === null) {
            $reasons[] = 'endpoint_value_or_FX_incomplete';
        }
        $change = $beginning['total'] === null || $ending['total'] === null ? null : bcsub($ending['total']['minor'], $beginning['total']['minor'], 0);
        if ($beginning['total'] !== null && bccomp($beginning['total']['minor'], '0', 0) <= 0) {
            $reasons[] = 'starting_value_not_positive';
        }
        $nativeStart = $nativeEnd = '0';
        $currencies = [];
        $nativeComplete = true;
        foreach ([$beginning, $ending] as $i => $point) {
            foreach ($point['components'] as $c) {
                $currencies[] = $c['currency'];
                if ($c['native'] === null) {
                    $nativeComplete = false;
                } elseif ($i === 0) {
                    $nativeStart = bcadd($nativeStart, $c['native']['minor'], 0);
                } else {
                    $nativeEnd = bcadd($nativeEnd, $c['native']['minor'], 0);
                }
            }
        }
        $nativeCurrency = count(array_unique($currencies)) === 1 ? $currencies[0] : null;
        $nativeEligible = $nativeComplete && $nativeCurrency !== null && $flows === [] && $shape($beginning) === $shape($ending);
        $nativeChange = $nativeEligible ? bcsub($nativeEnd, $nativeStart, 0) : null;

        $movement = null;
        $effect = null;
        if ($reasons === [] && $nativeChange !== null) {
            $movement = $this->fx->convert($owner, $nativeChange, Currency::from($nativeCurrency), $base, $start, 'reporting')['value'];
            $effect = $movement === null ? null : ValuationMath::money($base, bcsub($change, $movement['minor'], 0));
        }

        return ['label' => 'Simple value return · supported no-external-flow periods only', 'eligible' => $reasons === [], 'reasons' => $reasons,
            'base_return' => $reasons === [] ? ValuationMath::ratio($change, $beginning['total']['minor']) : null,
            'native_movement_at_start_FX' => $movement, 'FX_and_settlement_effect' => $effect, 'FX_decomposition_policy' => 'native monetary movement translated at starting reporting FX; residual is currency effect plus row-settlement rounding; no linear addition of percentages',
            'monetary_change' => ValuationMath::money($base, $change), 'external_movements' => $flows,
            'native_currency' => $nativeCurrency, 'native_return' => $nativeEligible ? ValuationMath::ratio($nativeChange, $nativeStart) : null,
            'native_monetary_change' => $nativeCurrency === null ? null : ValuationMath::money(Currency::from($nativeCurrency), $nativeChange),
            'flow_policy' => '(start UTC business date,end UTC business date]; investment transformations/internal native transfers excluded; FX/generic movements conservative',
            'income_policy' => 'Net income and fees already affect ending cash; never add again to value return.',
            'cash_flow_adjusted_metrics' => 'Deferred: time-weighted and money-weighted/XIRR metrics are unavailable.'];
    }

    public function save(User $owner, string $key, Currency $base, string $cutoff, ?string $account = null, ?string $start = null): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $base, $cutoff, $account, $start): stdClass {
            LedgerInput::lock($owner);
            $payload = ['kind' => 'investment_valuation_v1', 'base' => $base->value, 'cutoff' => LedgerInput::instant($cutoff, 'cutoff'), 'account' => $account, 'start' => $start === null ? null : LedgerInput::instant($start, 'start')];
            if ($cached = LedgerInput::cached('investment_valuation_runs', $owner, $key, $payload)) {
                return $cached;
            }
            DB::table('accounts')->where('owner_id', $owner->id)->orderBy('id')->lockForUpdate()->get();
            $evidence = $this->capture($owner, $base, $cutoff, $account, $start);
            $id = DB::table('investment_valuation_runs')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => $key,
                'payload_hash' => LedgerInput::hash($payload), 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'evidence_hash' => LedgerInput::hash($evidence)]);

            return LedgerInput::owned('investment_valuation_runs', $owner, (string) $id);
        });
    }
}
