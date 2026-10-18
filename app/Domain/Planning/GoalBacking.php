<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\LedgerInput;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

final class GoalBacking
{
    public function account(User $owner, string $id, bool $lock = false): Account
    {
        LedgerInput::identifier($id);
        $query = Account::query()->ownedBy($owner)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $account = $query->firstOrFail();
        if (! in_array($account->type->value, ['bank', 'cash'], true)) {
            LedgerInput::fail('account_id', 'Choose a bank or cash account. Investments and liabilities cannot back this goal.');
        }

        return $account;
    }

    /** @return array{cash: numeric-string|null, reason: ?string} */
    public function cash(Account $account): array
    {
        $balance = app(AccountBalanceReader::class)->forAccount($account);

        return ['cash' => $balance->money === null ? null : (bccomp($balance->money->minor, '0', 0) < 0 ? '0' : $balance->money->minor), 'reason' => $balance->money !== null && bccomp($balance->money->minor, '0', 0) < 0 ? 'negative_cash' : $balance->unavailableReason];
    }

    /** @return numeric-string */
    public function nominal(User $owner, string $accountId): string
    {
        return PlanningInput::minor((string) DB::table('goal_reservations as r')->where('r.owner_id', $owner->id)->where('r.account_id', $accountId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_releases as rel')->whereColumn('rel.reservation_id', 'r.id'))->selectRaw('coalesce(sum(r.amount_minor::numeric),0)::text as minor')->first()->minor);
    }

    /** @return array{nominal: string, backed: ?string, shortfall: ?string, reason: ?string} */
    public function goal(User $owner, stdClass $root, stdClass $current): array
    {
        if ($current->closed) {
            return ['nominal' => '0', 'backed' => '0', 'shortfall' => '0', 'reason' => 'closed'];
        }
        $cash = $this->cash($this->account($owner, (string) $root->account_id));
        if ($root->mode === 'dedicated') {
            // Recorded account high-water includes retained cancellations/corrections.
            // Per-journal net avoids posting-leg intermediate peaks; this is never extra cash.
            $peak = DB::selectOne(<<<'SQL'
SELECT greatest(?::numeric,coalesce(max(balance),?::numeric))::text AS minor FROM (
 SELECT ?::numeric+sum(movement) OVER(ORDER BY journal_id) AS balance FROM (
  SELECT p.journal_id,sum(p.amount_minor::numeric) AS movement FROM postings p JOIN journal_entries j ON j.id=p.journal_id
  WHERE p.owner_id=? AND p.account_id=? AND j.status IN ('posted','reversed') AND j.id>?
  GROUP BY p.journal_id
 ) movements
) balances
SQL, [$root->opening_minor, $root->opening_minor, $root->opening_minor, $owner->id, $root->account_id, $root->opening_journal_cutoff]);
            $nominal = PlanningInput::minor($peak->minor);
            if ($cash['cash'] !== null && bccomp($cash['cash'], $nominal, 0) > 0) {
                $nominal = $cash['cash'];
            }
            $backed = $cash['cash'];
        } else {
            $row = $this->reserved($owner, (string) $root->account_id, $cash['cash'], [(string) $root->id])[(string) $root->id] ?? ['nominal' => '0', 'backed' => '0'];
            $nominal = $row['nominal'];
            $backed = $cash['cash'] === null ? null : $row['backed'];
        }

        return ['nominal' => $nominal, 'backed' => $backed, 'shortfall' => $backed === null ? null : bcsub(PlanningInput::minor($nominal), PlanningInput::minor($backed), 0), 'reason' => $cash['reason']];
    }

    /** One account-wide FIFO window, filtered only after preceding claims are counted.
     * @param list<string> $roots
     * @return array<string, array{nominal: string, backed: string}> */
    public function reserved(User $owner, string $accountId, ?string $cash, array $roots): array
    {
        if ($roots === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($roots), '?'));
        $rows = DB::select("WITH active AS (
 SELECT r.id,r.goal_id,r.amount_minor::numeric AS amount,
 coalesce(sum(r.amount_minor::numeric) OVER(ORDER BY r.id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) AS earlier
 FROM goal_reservations r WHERE r.owner_id=? AND r.account_id=?
 AND NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id)
)
SELECT goal_id,coalesce(sum(amount),0)::text AS nominal,
 coalesce(sum(least(amount,greatest(0,?::numeric-earlier))),0)::text AS backed
FROM active WHERE goal_id IN ($marks) GROUP BY goal_id", [$owner->id, $accountId, $cash ?? '0', ...$roots]);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->goal_id] = ['nominal' => $row->nominal, 'backed' => $row->backed];
        }

        return $result;
    }
}
