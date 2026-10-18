<?php

namespace Tests\Support;

use App\Domain\Planning\AvailabilitySettings;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class AvailabilityFixtures
{
    /** @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public static function input(array $changes = []): array
    {
        return [...['prior_id' => '', 'currency' => 'QAR', 'buffer' => '50', 'next_income_date' => '2026-10-28', 'investment_target' => '0',
            'requirements' => [], 'overlaps' => [], 'investment_goals' => [], 'reason' => 'Synthetic explicit cash plan'], ...$changes];
    }

    /** @param array<string,mixed> $changes */
    public static function settings(User $owner, array $changes = []): stdClass
    {
        return app(AvailabilitySettings::class)->save($owner, (string) Str::uuid(), self::input($changes));
    }
}
