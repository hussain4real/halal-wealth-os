<?php

namespace Tests\Support;

final class DashboardFixtures
{
    /** @param array<string,string> $changes
     * @return array<string,string> */
    public static function settings(array $changes = []): array
    {
        return [...['settings_id' => '', 'reconciliation' => '1', 'funding' => '1', 'evidence' => '1', 'allocation' => '0', 'grace_days' => '0', 'targets' => '[]', 'reason' => 'Synthetic explicit review policy'], ...$changes];
    }

    /** @return array<string,mixed> */
    public static function facts(): array
    {
        return ['planning_date' => '2026-10-21', 'goals' => [], 'opportunities' => [], 'assessments' => [], 'reconciliations' => [],
            'valuation' => ['ending' => ['allocation' => ['coverage' => 'complete', 'denominator_minor' => '10000', 'dimensions' => ['currency' => [['label' => 'QAR', 'minor' => '6000'], ['label' => 'USD', 'minor' => '4000']]]]]]];
    }
}
