<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

final class BudgetActivity
{
    /** One SQL read retains economic sign, reversal lineage and explicit classification.
     * @return list<stdClass> */
    public function rows(User $owner): array
    {
        return array_values(DB::select(<<<'SQL'
SELECT j.id,j.reference,j.kind,j.status,j.effective_date,j.description,root.id AS original_id,root.kind AS direction,
 p.currency_code AS currency,
 (sum(p.amount_minor::numeric) * CASE WHEN root.kind='income' THEN -1 ELSE 1 END)::text AS minor,
 pc.id AS category_id,pc.family_reference,pc.name AS category_name,pc.normal_budget,pc.emergency_essential,pc.version AS category_version,
 pa.id AS attribution_id,
 EXISTS(SELECT 1 FROM journal_entries cancellation WHERE cancellation.owner_id=root.owner_id AND cancellation.reverses_journal_id=root.id
  AND to_char(cancellation.effective_date,'YYYY-MM')<>to_char(root.effective_date,'YYYY-MM')
  AND (EXISTS(SELECT 1 FROM journal_entries replacement WHERE replacement.owner_id=root.owner_id AND replacement.replaces_journal_id=root.id)
   OR EXISTS(SELECT 1 FROM fx_operation_journals member JOIN fx_operations replacement ON replacement.replaces_id=member.operation_id
    AND replacement.owner_id=member.owner_id WHERE member.owner_id=root.owner_id AND member.journal_id=root.id))
 ) AS legacy_timing
FROM journal_entries j
JOIN journal_entries root ON root.id=coalesce(j.reverses_journal_id,j.id) AND root.owner_id=j.owner_id AND root.kind IN ('income','expense')
JOIN postings p ON p.journal_id=j.id AND p.owner_id=j.owner_id
JOIN accounts a ON a.id=p.account_id AND a.owner_id=p.owner_id AND a.system_role=root.kind
LEFT JOIN planning_attributions pa ON pa.journal_id=root.id AND pa.owner_id=root.owner_id
 AND NOT EXISTS(SELECT 1 FROM planning_attributions newer WHERE newer.supersedes_id=pa.id)
LEFT JOIN planning_categories pc ON pc.id=pa.category_id AND pc.owner_id=pa.owner_id
WHERE j.owner_id=? AND j.status IN ('posted','reversed')
GROUP BY j.id,root.id,p.currency_code,pc.id,pa.id
ORDER BY j.id DESC,p.currency_code
SQL, [$owner->id]));
    }

    public function receipt(User $owner, string $id): stdClass
    {
        LedgerInput::owned('journal_entries', $owner, $id);
        $rows = array_values(array_filter($this->rows($owner), fn (stdClass $row): bool => (string) $row->id === $id && $row->kind !== 'reversal'));
        if (count($rows) !== 1 || bccomp($rows[0]->minor, '0', 0) <= 0) {
            LedgerInput::fail('journal_id', 'Choose one single-currency posted income or expense receipt. Transfers, openings and principal are not budget activity.');
        }
        try {
            Money::fromMinor(Currency::from($rows[0]->currency), $rows[0]->minor);
        } catch (InvalidArgumentException) {
            LedgerInput::fail('journal_id', 'This receipt amount is outside the supported allocation range.');
        }

        return $rows[0];
    }

    /** @return array<string, mixed> */
    public static function money(string $currency, string $minor, ?string $reason = null): array
    {
        try {
            if ($reason !== null) {
                return ['currency' => $currency, 'minor' => null, 'amount' => null, 'available' => false, 'reason' => $reason];
            }
            $money = Money::fromMinor(Currency::from($currency), $minor);

            return [...$money->jsonSerialize(), 'available' => true, 'reason' => null];
        } catch (InvalidArgumentException) {
            return ['currency' => $currency, 'minor' => null, 'amount' => null, 'available' => false, 'reason' => 'out_of_range'];
        }
    }
}
