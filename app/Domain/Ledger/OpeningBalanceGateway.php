<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\OpeningBalanceIntent;

/** Future posting boundary. A request is explicitly unposted, not a balance. */
interface OpeningBalanceGateway
{
    public function request(Account $account, Money $amount): OpeningBalanceIntent;
}
