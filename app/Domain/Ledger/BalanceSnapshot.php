<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use InvalidArgumentException;

/** Read-only derived values. An unposted request is never a movement. */
final readonly class BalanceSnapshot
{
    private function __construct(public Currency $currency, public ?Money $money, public ?string $unavailableReason = null) {}

    public static function unavailable(Currency $currency, string $reason = 'source_unavailable'): self
    {
        return new self($currency, null, $reason);
    }

    /** @param iterable<Money> $completeSignedMovements Debit positive, credit negative. */
    public static function derive(Currency $currency, iterable $completeSignedMovements): self
    {
        $minor = '0';
        foreach ($completeSignedMovements as $movement) {
            if ($movement->currency !== $currency) {
                throw new InvalidArgumentException('Balance movements must share one currency.');
            }
            $minor = bcadd($minor, $movement->minor, 0);
        }

        return new self($currency, Money::fromMinor($currency, $minor));
    }

    public function netWorthContribution(AccountType $type): ?Money
    {
        return $type->contributesToNetWorth() ? $this->money : null;
    }
}
