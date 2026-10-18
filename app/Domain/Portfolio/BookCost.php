<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Ledger\LedgerInput;

final class BookCost
{
    /** Allocate the integer basis by an exact rational; final disposal consumes the remainder.
     * @return numeric-string */
    public static function allocate(string $basis, string $sold, string $held): string
    {
        $basis = PortfolioInput::integer($basis);
        $sold = PortfolioInput::decimal($sold, 'quantity', true);
        $held = PortfolioInput::decimal($held, 'quantity', true);
        if (bccomp($basis, '0', 0) < 0 || bccomp($sold, $held, 12) > 0) {
            LedgerInput::fail('quantity', 'Allocation requires non-negative basis and a disposal within held units.');
        }
        if (bccomp($sold, $held, 12) === 0) {
            return $basis;
        }
        $numerator = bcmul($basis, bcmul($sold, '1000000000000', 0), 0);
        $denominator = bcmul($held, '1000000000000', 0);
        $floor = bcdiv($numerator, $denominator, 0);
        $remainder = bcsub($numerator, bcmul($floor, $denominator, 0), 0);

        return bccomp(bcmul($remainder, '2', 0), $denominator, 0) >= 0 ? bcadd($floor, '1', 0) : $floor;
    }

    public static function split(string $quantity, string $numerator, string $denominator): string
    {
        $quantity = PortfolioInput::decimal($quantity, 'quantity', true);
        $numerator = PortfolioInput::decimal($numerator, 'split_numerator', true);
        $denominator = PortfolioInput::decimal($denominator, 'split_denominator', true);
        $units = bcmul($quantity, '1000000000000', 0);
        $n = bcmul($units, bcmul($numerator, '1000000000000', 0), 0);
        $d = bcmul($denominator, '1000000000000', 0);
        if (bcmod($n, $d, 0) !== '0') {
            LedgerInput::fail('split_denominator', 'This split needs more than 12 quantity decimals. No units or cash-in-lieu were invented.');
        }

        return PortfolioInput::decimal(bcdiv(bcdiv($n, $d, 0), '1000000000000', 12), 'split_numerator', true);
    }
}
