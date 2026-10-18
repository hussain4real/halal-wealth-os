<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Models\Account;

/** Replaced only when the future posting service provides a complete source. */
final class UnavailableAccountBalances implements AccountBalanceReader
{
    public function forAccount(Account $account): BalanceSnapshot
    {
        return BalanceSnapshot::unavailable($account->currency_code);
    }
}
