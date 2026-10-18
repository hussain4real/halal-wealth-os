<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Models\Account;

interface AccountBalanceReader
{
    public function forAccount(Account $account): BalanceSnapshot;
}
