<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\FxMath;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\ValuationFx;
use App\Domain\Portfolio\ValuationMath;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Observations\ObservationRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

/** Reporting composes Portfolio with Ledger; ledger statements/reconciliation keep their cash-only meaning. */
final readonly class NetWorthSnapshot
{
    public const string POLICY = 'portfolio-mode-dated-prices-v3;sum-selected-component-settlements;manual-statement-same-UTC-date;receivable-once;raw-split-context;execution-price-not-quote;legacy-ledger-preserved';

    public function __construct(private FinancialSnapshot $ledger, private PortfolioRead $portfolio) {}

    /** @return array<string,mixed> */
    public function capture(User $owner, Currency $base, string $cutoff, ?string $accountId = null): array
    {
        return PortfolioRead::coherent(function () use ($owner, $base, $cutoff, $accountId): array {
            $snapshot = $this->ledger->capture($owner, $base, $cutoff, $accountId);
            $links = DB::table('portfolio_accounts')->where('owner_id', $owner->id)->get()->keyBy('account_id');
            $subtotal = '0';
            $covered = 0;
            foreach ($snapshot['components'] as &$component) {
                $p = $links->get($component['account_id']);
                if ($p !== null) {
                    $state = $this->portfolio->state($owner, (string) $p->id, $cutoff);
                    $contribution = $state['contribution'];
                    $component['ledger_cash'] = $component['native'];
                    $component['portfolio'] = ['id' => (string) $p->id, 'mode_id' => $state['mode_id'], 'mode' => $contribution['mode'],
                        'platform_label' => $state['platform_label'], 'source' => $state['source'], 'selected' => $contribution['selected'], 'excluded' => $contribution['excluded'],
                        'missing' => $contribution['missing'], 'as_of' => $contribution['as_of'], 'opening' => $state['opening'],
                        'positions' => $state['rows'], 'aggregate_value' => $state['aggregate_value'], 'aggregate_as_of' => $state['aggregate_as_of'],
                        'value_policy' => $state['manual_reference_policy'], 'investment' => $state['investment']];
                    $component['native'] = $contribution['value'];
                    $component['native_reason'] = $contribution['state'] === 'complete' ? null : 'portfolio:'.implode(',', $contribution['missing']);
                    $component['converted'] = null;
                    $component['state'] = 'unavailable';
                    if ($component['native'] !== null) {
                        $native = Money::fromMinor(Currency::from($component['currency']), $component['native']['minor']);
                        if ($native->currency === $base) {
                            $component['converted'] = $native->jsonSerialize();
                            $component['state'] = 'complete';
                        } elseif ($component['rate'] === null) {
                            $component['state'] = 'missing';
                        } else {
                            $rate = $component['rate'];
                            if (ObservationRead::payloadStale($rate, $cutoff)) {
                                $component['state'] = 'stale';
                            } else {
                                try {
                                    $component['converted'] = FxMath::convert($native, FxMath::rate($rate['value']), $base)->jsonSerialize();
                                    $component['state'] = 'complete';
                                } catch (InvalidArgumentException) {
                                    $component['native_reason'] = 'converted_out_of_range';
                                }
                            }
                        }
                    }
                }
                if ($p !== null && $contribution['mode'] === 'detailed' && $component['native'] !== null) {
                    $pieces = [$state['ledger_cash']['minor']];
                    foreach ($state['rows'] as $row) {
                        $pieces[] = $row['valuation']['market_value']['minor'];
                    }
                    $pieces[] = $state['investment']['receivable_minor'];
                    $translated = '0';
                    $available = true;
                    $failure = null;
                    foreach ($pieces as $minor) {
                        $part = app(ValuationFx::class)->convert($owner, $minor, Currency::from($component['currency']), $base, $cutoff, 'reporting');
                        if ($part['value'] === null) {
                            $available = false;
                            $failure ??= $part['reason'];
                        } else {
                            $translated = bcadd($translated, $part['value']['minor'], 0);
                        }
                    }
                    $component['converted'] = $available ? ValuationMath::money($base, $translated) : null;
                    $component['conversion_reason'] = $component['converted'] === null ? ($failure ?? 'converted_sum_out_of_range') : null;
                    $component['state'] = $component['converted'] !== null ? 'complete' : match ($failure) {
                        'fx_stale' => 'stale', 'fx_missing' => 'missing', default => 'unavailable',
                    };
                    $component['settlement_policy'] = 'sum_selected_component_settlements';
                }
                if ($component['converted'] !== null) {
                    $subtotal = bcadd($subtotal, $component['converted']['minor'], 0);
                    $covered++;
                }
            }
            unset($component);
            try {
                $aggregate = Money::fromMinor($base, $subtotal)->jsonSerialize();
            } catch (InvalidArgumentException) {
                $aggregate = null;
            }
            $complete = $covered === count($snapshot['components']) && $aggregate !== null;
            $snapshot['ledger_policy'] = $snapshot['policy'];
            $snapshot['policy'] = self::POLICY;
            $states = array_column($snapshot['components'], 'state');
            $snapshot['state'] = $complete ? 'complete' : ($covered > 0 ? 'partial' : (in_array('unavailable', $states, true) ? 'unavailable'
                : (count(array_unique($states)) === 1 && ($states[0] ?? '') === 'stale' ? 'stale' : 'missing')));
            if ($aggregate === null) {
                $snapshot['state'] = 'unavailable';
            }
            $snapshot['total'] = $complete ? $aggregate : null;
            $snapshot['covered_subtotal'] = $covered > 0 || $snapshot['components'] === [] ? $aggregate : null;
            $snapshot['subtotal_minor_exact'] = $subtotal;
            $snapshot['covered_accounts'] = $covered;

            return $snapshot;
        });
    }

    public function save(User $owner, string $key, Currency $base, string $cutoff): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $base, $cutoff): stdClass {
            LedgerInput::lock($owner);
            $payload = ['base_currency' => $base->value, 'cutoff' => LedgerInput::instant($cutoff, 'cutoff')];
            if ($cached = LedgerInput::cached('valuation_reports', $owner, $key, $payload)) {
                return $cached;
            }
            // Portfolio/ledger writes share the owner lock; account metadata writes
            // additionally serialize on these rows. No late SET TRANSACTION occurs.
            DB::table('accounts')->where('owner_id', $owner->id)->orderBy('id')->lockForUpdate()->get();
            $evidence = $this->capture($owner, $base, $cutoff);
            $id = DB::table('valuation_reports')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(),
                'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($payload), 'base_currency' => $base->value,
                'cutoff' => $cutoff, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'evidence_hash' => FinancialSnapshot::fingerprint($evidence)]);

            return LedgerInput::owned('valuation_reports', $owner, (string) $id);
        });
    }
}
