<?php

namespace Tests\Support;

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Portfolio\PortfolioService;
use App\Domain\Shared\Money\Currency;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class PortfolioFixtures
{
    /** @param array<string,mixed> $changes */
    public static function asset(User $owner, array $changes = []): stdClass
    {
        return app(PortfolioService::class)->asset($owner, (string) Str::uuid(), [...['prior_id' => '', 'name' => 'Synthetic Fractional Fund', 'kind' => 'unitized_fund', 'currency' => 'QAR',
            'identifier' => 'EXAMPLE-FUND', 'market' => 'Illustrative reference market', 'source' => 'Synthetic manual reference', 'unsupported_terms' => '', 'reason' => 'Synthetic catalog entry'], ...$changes]);
    }

    public static function account(User $owner, Currency $currency = Currency::QAR): Account
    {
        return app(CreateAccount::class)->create($owner, 'Synthetic investment account', AccountType::Investment, $currency, null);
    }

    public static function link(User $owner, Account $account): stdClass
    {
        return app(PortfolioService::class)->link($owner, (string) Str::uuid(), ['account_id' => (string) $account->id, 'platform_label' => 'Synthetic platform label', 'source' => 'Synthetic owner-entered statement']);
    }

    /** @param array<string,mixed> $changes */
    public static function modeInput(array $changes = []): array
    {
        return [...['mode' => 'detailed', 'platform_label' => 'Synthetic platform label', 'source' => 'Synthetic statement', 'reason' => 'Synthetic reconciliation', 'aggregate_value' => '', 'aggregate_as_of' => ''], ...$changes];
    }

    /** @param array<string,mixed> $changes */
    public static function mode(User $owner, stdClass $p, array $changes = []): stdClass
    {
        $preview = app(PortfolioService::class)->preview($owner, (string) $p->id, self::modeInput($changes));

        return app(PortfolioService::class)->confirm($owner, (string) $p->id, (string) Str::uuid(), (string) $preview->id, '1');
    }

    /** @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public static function row(stdClass $asset, array $changes = []): array
    {
        return [...['asset_id' => (string) $asset->id, 'quantity' => '1.234567890123', 'unit_price' => '2.000000000001', 'manual_value' => '', 'basis_known' => '0', 'cost_basis' => ''], ...$changes];
    }

    /** @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $changes */
    public static function opening(User $owner, stdClass $p, array $rows, array $changes = []): stdClass
    {
        return app(PortfolioService::class)->opening($owner, (string) $p->id, (string) Str::uuid(), [...['prior_id' => '', 'as_of' => now('UTC')->toDateString(), 'source' => 'Synthetic opening statement', 'reason' => 'Synthetic opening capture', 'rows' => $rows], ...$changes]);
    }
}
