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
        // Opening existence, journal status, completeness and posted sum must
        // share one statement snapshot, including when a concurrent writer commits.
        $result = DB::selectOne(<<<'SQL'
SELECT
 i.amount_minor::text AS opening_minor, opening.id::text AS opening_journal_id, opening.status AS opening_status,
 (SELECT coalesce(sum(p.amount_minor::numeric),0)::text FROM postings p JOIN journal_entries j ON j.id = p.journal_id
  WHERE p.account_id = a.id AND p.owner_id = a.owner_id AND j.owner_id = a.owner_id AND j.status IN ('posted','reversed')) AS minor
FROM accounts a
LEFT JOIN opening_balance_intents i ON i.account_id = a.id
LEFT JOIN opening_balance_postings link ON link.opening_intent_id = i.id
LEFT JOIN journal_entries opening ON opening.id = link.journal_id AND opening.owner_id = a.owner_id AND opening.status IN ('posted','reversed')
WHERE a.id = ? AND a.owner_id = ?
SQL, [$account->id, $account->owner_id]);
        if ($result === null) {
            return BalanceSnapshot::unavailable($account->currency_code);
        }
        $opening = $result->opening_minor === null ? null : new OpeningRequestSnapshot(
            Money::fromMinor($account->currency_code, $result->opening_minor),
            $result->opening_journal_id,
            $result->opening_status ?? 'awaiting_posting',
        );
        if ($opening !== null && $opening->journalId === null) {
            return BalanceSnapshot::unavailable($account->currency_code, 'opening_pending', $opening);
        }
        try {
            return BalanceSnapshot::derive($account->currency_code, [Money::fromMinor($account->currency_code, $result->minor)], $opening);
        } catch (InvalidArgumentException) {
            return BalanceSnapshot::unavailable($account->currency_code, 'out_of_range', $opening);
        }
    }
}
