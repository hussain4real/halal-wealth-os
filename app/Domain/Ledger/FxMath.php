<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\ExactDecimal;
use App\Domain\Shared\Money\Money;
use InvalidArgumentException;

final class FxMath
{
    public static function rate(mixed $value): ExactDecimal
    {
        $rate = ExactDecimal::fromString($value);
        if (bccomp($rate->value, '0', ExactDecimal::SCALE) <= 0) {
            throw new InvalidArgumentException('An FX rate must be greater than zero.');
        }

        return $rate;
    }

    public static function convert(Money $native, ExactDecimal $rate, Currency $quote): Money
    {
        self::rate($rate->value);
        // Cash can have 19 major integer digits (JPY). Keep this intermediate
        // outside ExactDecimal's 18-integer-digit storage contract.
        $scale = ExactDecimal::SCALE + $native->currency->exponent();
        $major = bcdiv(bcmul($native->minor, $rate->value, ExactDecimal::SCALE), bcpow('10', (string) $native->currency->exponent(), 0), $scale);
        $scaled = bcmul($major, bcpow('10', (string) $quote->exponent(), 0), $scale);
        $minor = bcadd($scaled, '0', 0);
        if (bccomp(ltrim(bcsub($scaled, $minor, $scale), '-'), '0.5', $scale) >= 0) {
            $minor = bcadd($minor, str_starts_with($scaled, '-') ? '-1' : '1', 0);
        }

        return Money::fromMinor($quote, $minor);
    }

    /** @return array{numerator: string, denominator: string, display: string, rounded: bool} */
    public static function implied(Money $source, Money $destination): array
    {
        $numerator = bcmul($destination->minor, bcpow('10', (string) $source->currency->exponent(), 0), 0);
        $denominator = bcmul($source->minor, bcpow('10', (string) $destination->currency->exponent(), 0), 0);
        $display = bcdiv($numerator, $denominator, 12);

        return ['numerator' => $numerator, 'denominator' => $denominator, 'display' => $display, 'rounded' => bccomp(bcmul($display, $denominator, 12), $numerator, 12) !== 0];
    }

    /** @return list<array{account_id: string, minor: string}> */
    public static function legs(string $accountId, string $minor): array
    {
        if (! is_numeric($minor) || ! preg_match('/^-?[0-9]+$/D', $minor)) {
            throw new InvalidArgumentException('Posting legs require an integer minor-unit string.');
        }
        if (bccomp($minor, Money::MAX_MINOR, 0) > 0) {
            return [['account_id' => $accountId, 'minor' => Money::MAX_MINOR], ['account_id' => $accountId, 'minor' => bcsub($minor, Money::MAX_MINOR, 0)]];
        }

        return [['account_id' => $accountId, 'minor' => $minor]];
    }
}
