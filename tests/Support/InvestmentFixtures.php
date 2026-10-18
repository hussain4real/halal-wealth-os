<?php

namespace Tests\Support;

use App\Domain\Ledger\PostingService;
use App\Domain\Portfolio\InvestmentReplay;
use App\Domain\Portfolio\InvestmentService;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class InvestmentFixtures
{
    /** @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public static function input(stdClass $asset, array $changes = []): array
    {
        $kind = $changes['kind'] ?? 'buy';
        $units = in_array($kind, ['buy', 'sell', 'ipo_allot'], true);

        return [...['kind' => $kind, 'asset_id' => (string) $asset->id, 'effective_date' => now('UTC')->toDateString(),
            'quantity' => $units ? '8' : '', 'unit_price' => $units ? '7' : '', 'amount' => in_array($kind, ['split', 'ipo_cancel'], true) ? '0' : '56',
            'fee' => $kind === 'buy' ? '0.08' : '0', 'tax' => '0', 'split_numerator' => $kind === 'split' ? '2' : '', 'split_denominator' => $kind === 'split' ? '1' : '',
            'subscription_id' => '', 'confirmed' => $kind === 'ipo_allot' ? '1' : '', 'replaces_id' => '',
            'action_reference' => 'SYNTHETIC-'.Str::ulid(), 'source' => 'Synthetic statement evidence', 'reason' => 'Synthetic recorded fact'], ...$changes];
    }

    /** @param array<string,mixed> $changes */
    public static function event(User $owner, stdClass $p, stdClass $asset, array $changes = [], ?string $key = null): stdClass
    {
        return app(InvestmentService::class)->record($owner, (string) $p->id, $key ?? (string) Str::uuid(), self::input($asset, $changes));
    }

    public static function fund(User $owner, Account $account, string $amount = '2000', ?string $date = null): void
    {
        app(PostingService::class)->record($owner, (string) Str::uuid(), ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => $amount,
            'effective_date' => $date ?? now('UTC')->toDateString(), 'description' => 'Synthetic investment cash funding']);
    }

    /** @return array<string,mixed> */
    public static function replay(User $owner, stdClass $p): array
    {
        return app(InvestmentReplay::class)->capture($owner, (string) $p->id, now('UTC')->format('Y-m-d\TH:i:s\Z'));
    }
}
