<?php

namespace Tests\Support;

use App\Domain\Ledger\PostingService;
use App\Domain\Planning\GoalService;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class GoalFixtures
{
    /** @param array<string, string> $changes
     * @return array<string, string> */
    public static function input(Account $account, array $changes = []): array
    {
        return [...['name' => 'Synthetic safety reserve', 'account_id' => (string) $account->id, 'currency' => $account->currency_code->value, 'mode' => 'reserved', 'target' => '24000', 'opening' => '4800', 'policy' => 'fixed_monthly', 'contribution' => '1600', 'first_deposit' => '', 'target_date' => '', 'standing_order' => '1', 'closed' => '0', 'reason' => ''], ...$changes];
    }

    public static function cash(User $owner, Account $account, string $amount = '4800'): JournalEntry
    {
        return app(PostingService::class)->record($owner, (string) Str::uuid(), ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => $amount, 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic existing cash']);
    }

    /** @param array<string, string> $changes */
    public static function goal(User $owner, Account $account, array $changes = []): stdClass
    {
        return app(GoalService::class)->plan($owner, (string) Str::uuid(), self::input($account, $changes));
    }
}
