<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Observations\ObservationRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PositionPricing
{
    public const string POLICY = 'manual-raw-account-family;effective-observed-asof;latest-effective-observed-id;7d-inclusive;split-context-exact;native-settlement-half-up';

    /** @param array<string,mixed> $row
     * @param array<string,mixed>|null $opening
     * @return array<string,mixed> */
    public function value(User $owner, string $account, array $row, ?array $opening, string $cutoff): array
    {
        $price = null;
        $value = null;
        $reason = null;
        if ($row['kind'] === 'manual_investment') {
            $value = ($opening['as_of'] ?? null) === substr($cutoff, 0, 10) ? $row['manual_value'] : null;
            $reason = $value === null ? 'manual_statement_missing_for_date' : null;
        } else {
            $quote = ObservationRead::latest(DB::table('portfolio_prices')->where('owner_id', $owner->id)->where('portfolio_account_id', $account)->where('asset_family', $row['asset_family'])
                ->where('currency', $row['currency']), $cutoff);
            if ($quote !== null) {
                $price = ['id' => (string) $quote->id, 'asset_id' => (string) $quote->asset_id, 'series_id' => $quote->series_id, 'version' => (string) $quote->version,
                    'value' => (string) $quote->unit_price, 'currency' => $quote->currency, 'source' => $quote->source,
                    ...ObservationRead::evidence($quote), 'split_basis' => $quote->split_basis];
                $reason = ObservationRead::stale($quote, $cutoff) ? 'price_stale' : ($quote->split_basis !== PriceObservationService::splitBasis($owner, $account, $row['asset_family'], substr($cutoff, 0, 10)) ? 'price_split_basis_changed' : null);
            } elseif (($opening['as_of'] ?? null) === substr($cutoff, 0, 10) && $row['unit_price'] !== null) {
                $price = ['id' => null, 'value' => $row['unit_price'], 'source' => $opening['source'], 'opening_id' => $opening['id'], 'effective_date' => $opening['as_of']];
            } else {
                $reason = 'price_missing';
            }
            if ($reason === null) {
                try {
                    $value = PortfolioInput::product(Currency::from($row['currency']), $row['quantity'], $price['value'])->jsonSerialize();
                } catch (InvalidArgumentException) {
                    $reason = 'market_value_out_of_range';
                }
            }
        }
        $gain = null;
        if ($value !== null && $row['cost_basis'] !== null) {
            try {
                $gain = Money::fromMinor(Currency::from($row['currency']), bcsub($value['minor'], $row['cost_basis']['minor'], 0))->jsonSerialize();
            } catch (InvalidArgumentException) {
                // A valid value and basis can still have a difference outside signed Money.
            }
        }

        return ['policy' => self::POLICY, 'market_value' => $value, 'reason' => $reason, 'price' => $price, 'unrealized_gain' => $gain,
            'gain_reason' => $gain !== null ? null : ($row['cost_basis'] === null ? 'basis_unknown' : ($reason ?? 'gain_out_of_range'))];
    }
}
