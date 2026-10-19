<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use InvalidArgumentException;

final class ValuationMath
{
    /** @return array{numerator:string,denominator:string,percent:string}|null */
    public static function ratio(string $numerator, string $denominator): ?array
    {
        $numerator = PortfolioInput::integer($numerator);
        $denominator = PortfolioInput::integer($denominator);
        if (bccomp($denominator, '0', 0) <= 0) {
            return null;
        }
        $scaled = bcdiv(bcmul($numerator, '100', 0), $denominator, 8);
        $display = bcdiv($scaled, '1', 4);
        if (bccomp(ltrim(bcsub($scaled, $display, 8), '-'), '0.00005', 8) >= 0) {
            $display = bcadd($display, str_starts_with($scaled, '-') ? '-0.0001' : '0.0001', 4);
        }

        return ['numerator' => $numerator, 'denominator' => $denominator, 'percent' => $display];
    }

    /** Exact signed integer ratio allocation; final consumption retains the residue.
     * @return numeric-string */
    public static function allocate(string $pool, string $part, string $whole): string
    {
        $pool = PortfolioInput::integer($pool);
        $part = PortfolioInput::integer($part);
        $whole = PortfolioInput::integer($whole);
        if (bccomp($whole, '0', 0) <= 0 || bccomp($part, '0', 0) < 0 || bccomp($part, $whole, 0) > 0) {
            throw new InvalidArgumentException('Allocation requires a positive denominator and a part within it.');
        }
        if (bccomp($part, $whole, 0) === 0) {
            return $pool;
        }
        $n = bcmul($pool, $part, 0);
        $q = bcdiv($n, $whole, 0);
        if (bccomp(bcmul(ltrim(bcsub($n, bcmul($q, $whole, 0), 0), '-'), '2', 0), $whole, 0) >= 0) {
            $q = bcadd($q, str_starts_with($n, '-') ? '-1' : '1', 0);
        }

        return $q;
    }

    /** @return array{currency:string,minor:numeric-string,amount:string}|null */
    public static function money(Currency $currency, ?string $minor): ?array
    {
        if ($minor === null) {
            return null;
        }
        try {
            return Money::fromMinor($currency, $minor)->jsonSerialize();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
