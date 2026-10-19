<?php

namespace Tests\Support;

use App\Domain\Ledger\FxObservationService;
use App\Domain\Portfolio\PriceObservationService;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class ValuationFixtures
{
    /** @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public static function input(stdClass $p, stdClass $asset, array $changes = []): array
    {
        return [...['context' => app(PriceObservationService::class)->context(User::findOrFail($p->owner_id), (string) $p->id), 'portfolio_account_id' => (string) $p->id, 'asset_id' => (string) $asset->id, 'unit_price' => '120',
            'source' => 'Synthetic dated observation', 'observed_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'effective_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'supersedes_id' => '', 'reason' => 'Synthetic observation capture', 'unit_basis_confirmed' => '1'], ...$changes];
    }

    /** @param array<string,mixed> $changes */
    public static function price(User $owner, stdClass $p, stdClass $asset, array $changes = [], ?string $key = null): stdClass
    {
        return app(PriceObservationService::class)->record($owner, $key ?? (string) Str::uuid(), self::input($p, $asset, $changes));
    }

    public static function fx(User $owner, string $date, string $value, string $purpose = 'reporting', string $native = 'NGN', string $base = 'QAR'): stdClass
    {
        return app(FxObservationService::class)->record($owner, (string) Str::uuid(), ['base_currency' => $native, 'quote_currency' => $base, 'value' => $value,
            'source' => 'Synthetic FX evidence', 'observed_at' => $date.'T23:59:59Z', 'effective_at' => $date.'T23:59:59Z'], $purpose);
    }
}
