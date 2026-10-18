<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum AccountType: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Investment = 'investment';
    case Liability = 'liability';
    case InternalClearing = 'internal_clearing';
    case InternalEquity = 'internal_equity';
    case InternalIncome = 'internal_income';
    case InternalExpense = 'internal_expense';

    public function contributesToNetWorth(): bool
    {
        return in_array($this, [self::Bank, self::Cash, self::Investment, self::Liability], true);
    }
}
