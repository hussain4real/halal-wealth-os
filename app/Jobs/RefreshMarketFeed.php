<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class RefreshMarketFeed implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /** @param array<string,string> $feed */
    public function __construct(public array $feed, public string $window) {}

    public function handle(MarketRefresh $refresh): void
    {
        $outcome = $refresh->refresh(MarketFeed::from($this->feed), $this->window);
        if (($outcome['blocked_mappings'] ?? 0) > 0) {
            Log::warning(($outcome['code'] ?? '') === 'evidence_capacity' ? 'Market refresh admission capacity reached.' : 'Market refresh has held admissions.', [
                'code' => $outcome['code'] ?? 'mapping_or_capacity_changed', 'accepted_mappings' => $outcome['mappings'],
                'blocked_mappings' => $outcome['blocked_mappings'],
            ]);
        }
    }
}
