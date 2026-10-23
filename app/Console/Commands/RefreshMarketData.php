<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\MarketRefresh;
use Illuminate\Console\Command;

final class RefreshMarketData extends Command
{
    protected $signature = 'market:refresh';

    protected $description = 'Queue disabled-by-default synthetic market refreshes';

    public function handle(MarketRefresh $refresh): int
    {
        $this->info('Queued feeds: '.$refresh->dispatchDue().'. Live connectivity remains blocked.');

        return self::SUCCESS;
    }
}
