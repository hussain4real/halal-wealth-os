<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RefreshMarketFeed implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /** @param array<string,string> $feed */
    public function __construct(public array $feed, public string $window) {}

    public function handle(MarketRefresh $refresh): void
    {
        $refresh->refresh(MarketFeed::from($this->feed), $this->window);
    }
}
