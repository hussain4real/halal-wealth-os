<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

enum Currency: string
{
    case QAR = 'QAR';
    case NGN = 'NGN';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case JPY = 'JPY';
    case KWD = 'KWD';

    public function exponent(): int
    {
        return match ($this) {
            self::JPY => 0,
            self::KWD => 3,
            default => 2,
        };
    }
}
