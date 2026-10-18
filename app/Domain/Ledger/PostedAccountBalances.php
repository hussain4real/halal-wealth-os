<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Money;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostedAccountBalances implements AccountBalanceReader
{
    public function forAccount(Account $account): BalanceSnapshot
    {
        // One statement/snapshot: opening completeness and posted sum agree even
        // if another connection commits an opening between two reader calls.
        $result = DB::selectOne(<<<'SQL'
SELECT
 EXISTS (SELECT 1 FROM opening_balance_intents i WHERE i.account_id = ? AND NOT EXISTS
   (SELECT 1 FROM opening_balance_postings l JOIN journal_entries j ON j.id = l.journal_id
    WHERE l.opening_intent_id = i.id AND j.status IN ('posted','reversed'))) AS unresolved,
 (SELECT coalesce(sum(p.amount_minor::numeric),0)::text FROM postings p JOIN journal_entries j ON j.id = p.journal_id
  WHERE p.account_id = ? AND p.owner_id = ? AND j.owner_id = ? AND j.status IN ('posted','reversed')) AS minor
SQL, [$account->id, $account->id, $account->owner_id, $account->owner_id]);
        if ($result->unresolved) {
            return BalanceSnapshot::unavailable($account->currency_code, 'opening_pending');
        }
        try {
            return BalanceSnapshot::derive($account->currency_code, [Money::fromMinor($account->currency_code, $result->minor)]);
        } catch (InvalidArgumentException) {
            return BalanceSnapshot::unavailable($account->currency_code, 'out_of_range');
        }
    }
}
