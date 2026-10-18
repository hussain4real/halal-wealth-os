<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Money implements JsonSerializable
{
    public const string MIN_MINOR = '-9223372036854775808';

    public const string MAX_MINOR = '9223372036854775807';

    /** @var numeric-string */
    public string $minor;

    /** @param numeric-string $minor */
    private function __construct(public Currency $currency, string $minor)
    {
        $this->minor = $minor;
    }

    public static function fromMinor(Currency $currency, mixed $minor): self
    {
        if (! is_string($minor) || strlen($minor) > 20 || ! preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $minor) || ! is_numeric($minor)) {
            throw new InvalidArgumentException('Minor units must be an integer string.');
        }
        if (bccomp($minor, self::MIN_MINOR, 0) < 0 || bccomp($minor, self::MAX_MINOR, 0) > 0) {
            throw new InvalidArgumentException('Amount exceeds the signed 64-bit minor-unit range.');
        }

        return new self($currency, $minor === '-0' ? '0' : $minor);
    }

    /** Strict user input: excessive scale is rejected, even when digits are zero. */
    public static function fromDecimal(Currency $currency, mixed $amount): self
    {
        if (! is_string($amount) || strlen($amount) > 32 || ! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $amount) || ! is_numeric($amount)) {
            throw new InvalidArgumentException('Amount must be an exact decimal string.');
        }
        $fraction = explode('.', $amount)[1] ?? '';
        if (strlen($fraction) > $currency->exponent()) {
            throw new InvalidArgumentException('Amount has more fractional digits than the currency permits.');
        }

        return self::fromMinor($currency, bcmul($amount, self::factor($currency), 0));
    }

    /** Explicit cash settlement only: HALF_UP ties go away from zero. */
    public static function settle(Currency $currency, ExactDecimal $amount): self
    {
        $scaled = bcmul($amount->value, self::factor($currency), ExactDecimal::SCALE);
        $minor = bcadd($scaled, '0', 0);
        $remainder = ltrim(bcsub($scaled, $minor, ExactDecimal::SCALE), '-');
        if (bccomp($remainder, '0.5', ExactDecimal::SCALE) >= 0) {
            $minor = bcadd($minor, str_starts_with($scaled, '-') ? '-1' : '1', 0);
        }

        return self::fromMinor($currency, $minor);
    }

    public function plus(self $other): self
    {
        $this->requireSameCurrency($other);

        return self::fromMinor($this->currency, bcadd($this->minor, $other->minor, 0));
    }

    public function minus(self $other): self
    {
        $this->requireSameCurrency($other);

        return self::fromMinor($this->currency, bcsub($this->minor, $other->minor, 0));
    }

    public function negated(): self
    {
        return self::fromMinor($this->currency, bcsub('0', $this->minor, 0));
    }

    public function amount(): string
    {
        $absolute = ltrim($this->minor, '-');

        return (str_starts_with($this->minor, '-') ? '-' : '').bcdiv($absolute, self::factor($this->currency), $this->currency->exponent());
    }

    /** @return array{currency: string, minor: string, amount: string} */
    public function jsonSerialize(): array
    {
        return ['currency' => $this->currency->value, 'minor' => $this->minor, 'amount' => $this->amount()];
    }

    /** @return numeric-string */
    private static function factor(Currency $currency): string
    {
        return match ($currency->exponent()) {
            0 => '1', 2 => '100', 3 => '1000',
            default => throw new InvalidArgumentException('Unsupported currency exponent.'),
        };
    }

    private function requireSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Mixed currencies require an explicit conversion; no conversion is available here.');
        }
    }
}
