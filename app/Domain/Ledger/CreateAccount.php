<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateAccount
{
    public function __construct(private OpeningBalanceGateway $openingBalances) {}

    public function create(User $owner, string $name, AccountType $type, Currency $currency, ?Money $opening): Account
    {
        // Atomic metadata/intent creation only: this writes no journal or posting.
        return DB::transaction(function () use ($owner, $name, $type, $currency, $opening): Account {
            $account = new Account(['name' => $name, 'type' => $type, 'currency_code' => $currency]);
            $account->owner()->associate($owner);
            $account->save();
            if ($opening !== null) {
                $this->openingBalances->request($account, $opening);
            }

            return $account;
        });
    }
}
