<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use InvalidArgumentException;
use JsonSerializable;

/** Exact signed NUMERIC(30,12) representation, without implicit rounding. */
final readonly class ExactDecimal implements JsonSerializable
{
    public const int SCALE = 12;

    /** @param numeric-string $value */
    private function __construct(public string $value) {}

    public static function fromString(mixed $value): self
    {
        if (! is_string($value) || strlen($value) > 32 || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)) {
            throw new InvalidArgumentException('Use an exact decimal string without exponent notation or separators.');
        }

        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        if (strlen($integer) > 18 || strlen($fraction) > self::SCALE) {
            throw new InvalidArgumentException('Decimal exceeds 18 integer digits or 12 fractional digits; no truncation is permitted.');
        }

        return new self(self::normalize($value));
    }

    public function plus(self $other): self
    {
        return self::fromString(self::normalize(bcadd($this->value, $other->value, self::SCALE)));
    }

    public function multipliedBy(self $other): self
    {
        // Compute the entire product first; reject an unrepresentable result.
        return self::fromString(self::normalize(bcmul($this->value, $other->value, self::SCALE * 2)));
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /** @return numeric-string */
    private static function normalize(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        $value = $value === '-0' ? '0' : $value;
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Invalid exact decimal result.');
        }

        return $value;
    }
}
