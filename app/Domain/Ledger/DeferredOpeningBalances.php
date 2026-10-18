<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\OpeningBalanceIntent;
use InvalidArgumentException;

final class DeferredOpeningBalances implements OpeningBalanceGateway
{
    public function request(Account $account, Money $amount): OpeningBalanceIntent
    {
        return $account->getConnection()->transaction(function () use ($account, $amount): OpeningBalanceIntent {
            $current = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($current->archived_at !== null || $current->currency_code !== $amount->currency) {
                throw new InvalidArgumentException('Opening request requires an active account in the same currency.');
            }

            return $current->openingBalanceIntent()->create([
                'currency_code' => $amount->currency,
                'amount_minor' => $amount->minor,
            ]);
        });
    }
}
