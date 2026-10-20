<?php

declare(strict_types=1);

namespace App\Domain\Shariah;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\ExactDecimal;
use App\Domain\Shared\Money\Money;
use InvalidArgumentException;

final class PurificationMath
{
    public static function percentage(Currency $currency, string $baseMinor, string $rate): Money
    {
        $base = Money::fromMinor($currency, $baseMinor);
        $percent = ExactDecimal::fromString($rate);
        if (bccomp($base->minor, '0', 0) < 0 || bccomp($percent->value, '0', 12) < 0) {
            throw new InvalidArgumentException('An income base and sourced percentage must be nonnegative.');
        }
        // Full integer rational: twelve percentage decimals, then /100. Settle once.
        $numerator = bcmul($base->minor, bcmul($percent->value, '1000000000000', 0), 0);
        $denominator = '100000000000000';
        $minor = bcdiv($numerator, $denominator, 0);
        if (bccomp(bcmul(bcmod($numerator, $denominator, 0), '2', 0), $denominator, 0) >= 0) {
            $minor = bcadd($minor, '1', 0);
        }

        return Money::fromMinor($currency, $minor);
    }
}
