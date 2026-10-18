<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\ExactDecimal;
use App\Domain\Shared\Money\Money;
use InvalidArgumentException;

final class PortfolioInput
{
    /** @param array<string,mixed> $input
     * @param list<string> $allowed */
    public static function only(array $input, array $allowed): void
    {
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            LedgerInput::fail((string) $field, 'This field cannot be submitted.');
        }
    }

    public static function optional(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : LedgerInput::text($value, $field);
    }

    public static function currency(mixed $value): Currency
    {
        $c = is_string($value) ? Currency::tryFrom($value) : null;
        if ($c === null) {
            LedgerInput::fail('currency', 'Choose a supported native currency.');
        }

        return $c;
    }

    /** @return numeric-string */
    public static function decimal(mixed $value, string $field, bool $positive = false): string
    {
        try {
            $v = ExactDecimal::fromString($value)->value;
            if (bccomp($v, '0', 12) < 0 || ($positive && bccomp($v, '0', 12) === 0)) {
                throw new InvalidArgumentException($positive ? 'Quantity must be positive.' : 'Value cannot be negative.');
            }

            return $v;
        } catch (InvalidArgumentException $e) {
            LedgerInput::fail($field, $e->getMessage());
        }
    }

    public static function money(Currency $currency, mixed $value, string $field, bool $signed = false): string
    {
        try {
            $m = Money::fromDecimal($currency, $value);
            if (! $signed && bccomp($m->minor, '0', 0) < 0) {
                throw new InvalidArgumentException('Value cannot be negative.');
            }

            return $m->minor;
        } catch (InvalidArgumentException $e) {
            LedgerInput::fail($field, $e->getMessage());
        }
    }

    /** Settle a full 24-place non-negative product without narrowing it first. */
    public static function product(Currency $currency, string $quantity, string $price): Money
    {
        $quantity = self::decimal($quantity, 'quantity', true);
        $price = self::decimal($price, 'unit_price');
        $scaled = bcmul(bcmul($quantity, $price, 24), bcpow('10', (string) $currency->exponent(), 0), 24);
        $minor = bcadd($scaled, '0', 0);
        if (bccomp(bcsub($scaled, $minor, 24), '0.5', 24) >= 0) {
            $minor = bcadd($minor, '1', 0);
        }

        return Money::fromMinor($currency, $minor);
    }
}
