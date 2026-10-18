<?php

namespace Tests\Support;

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Shared\Money\Currency;
use App\Models\Account;
use App\Models\User;

final class FxFixtures
{
    public static function account(User $owner, Currency $currency = Currency::QAR): Account
    {
        return app(CreateAccount::class)->create($owner, 'Synthetic '.$currency->value, AccountType::Bank, $currency, null);
    }

    /** @return array<string, string> */
    public static function input(Account $source, Account $destination): array
    {
        return ['source_account_id' => (string) $source->id, 'destination_account_id' => (string) $destination->id, 'source_amount' => '73.25',
            'destination_amount' => '29666.25', 'fee_amount' => '2.50', 'fee_side' => 'source', 'rate_value' => '405', 'rate_source' => 'Synthetic manual receipt',
            'observed_at' => now('UTC')->startOfDay()->format('Y-m-d\TH:i:s\Z'), 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic currency exchange'];
    }

    /** @return array<string, string> */
    public static function quote(string $base = 'NGN', string $quote = 'QAR', string $value = '0.002469135802'): array
    {
        return ['base_currency' => $base, 'quote_currency' => $quote, 'value' => $value, 'source' => 'Synthetic reporting quote',
            'observed_at' => now('UTC')->startOfDay()->format('Y-m-d\TH:i:s\Z'), 'effective_at' => now('UTC')->startOfDay()->format('Y-m-d\TH:i:s\Z')];
    }
}
