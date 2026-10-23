<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Ledger\FxMath;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Observations\ObservationRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ValuationFx
{
    /** @return array<string,mixed> */
    public function convert(User $owner, string $minor, Currency $native, Currency $base, string $cutoff, string $purpose): array
    {
        $minor = PortfolioInput::integer($minor);
        if ($native === $base || bccomp($minor, '0', 0) === 0) {
            return ['value' => ValuationMath::money($base, $minor), 'rate' => null, 'reason' => null, 'conversion' => $native === $base ? 'same_currency' : 'zero_amount_identity'];
        }
        $q = ObservationRead::latest(DB::table('fx_observations')->where('owner_id', $owner->id)->where('base_currency', $native->value)->where('quote_currency', $base->value)
            ->where('purpose', $purpose), $cutoff);
        $rate = $q === null ? null : ['id' => (string) $q->id, 'series_id' => $q->series_id, 'version' => (string) $q->version, 'value' => (string) $q->value,
            'source' => $q->source, 'base' => $native->value, 'quote' => $base->value, 'purpose' => $purpose,
            ...ObservationRead::evidence($q)];
        $reason = $rate === null ? 'fx_missing' : null;
        $value = null;
        if ($rate !== null) {
            $reason = ObservationRead::stale($q, $cutoff) ? 'fx_stale' : null;
            if ($reason === null) {
                try {
                    $value = FxMath::convert(Money::fromMinor($native, $minor), FxMath::rate((string) $q->value), $base)->jsonSerialize();
                } catch (InvalidArgumentException) {
                    $reason = 'fx_value_out_of_range';
                }
            }
        }

        return ['value' => $value, 'rate' => $rate, 'reason' => $reason, 'conversion' => 'direct_'.$purpose.'_rate'];
    }
}
