<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PlanningInput
{
    /** @param array<string, mixed> $input
     * @param list<string> $allowed */
    public static function only(array $input, array $allowed): void
    {
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            LedgerInput::fail($field, 'This field cannot be submitted.');
        }
    }

    /** Validate integer SQL aggregates before exact BCMath arithmetic, including sums beyond Money's storage range.
     * @return numeric-string */
    public static function minor(string $value): string
    {
        if (! is_numeric($value) || ! preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)) {
            throw new InvalidArgumentException('Expected exact integer minor units.');
        }

        return $value;
    }

    public static function date(mixed $value, string $field = 'first_due_date'): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Qatar')) : false;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || ! $date || $date->format('Y-m-d') !== $value || $value < '0001-01-01' || $value > '9998-12-31') {
            LedgerInput::fail($field, 'Choose a valid Qatar calendar date between years 0001 and 9998.');
        }

        return $value;
    }

    public static function month(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}$/D', $value)) {
            LedgerInput::fail('month', 'Choose a calendar month.');
        }
        self::date($value.'-01', 'month');

        return $value;
    }

    public static function flag(mixed $value, string $field): bool
    {
        if (! in_array($value, ['0', '1'], true)) {
            LedgerInput::fail($field, 'Choose yes or no explicitly.');
        }

        return $value === '1';
    }

    public static function currency(mixed $value): Currency
    {
        $currency = is_string($value) ? Currency::tryFrom($value) : null;
        if ($currency === null) {
            LedgerInput::fail('currency', 'Choose a supported currency.');
        }

        return $currency;
    }

    public static function positive(Currency $currency, mixed $value, string $field = 'amount'): Money
    {
        try {
            $money = Money::fromDecimal($currency, $value);
            if (bccomp($money->minor, '0', 0) <= 0) {
                throw new InvalidArgumentException('Enter a positive exact amount.');
            }

            return $money;
        } catch (InvalidArgumentException $error) {
            LedgerInput::fail($field, $error->getMessage());
        }
    }
}
