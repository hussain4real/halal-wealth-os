<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\Operations;
use Illuminate\Console\Command;

final class PruneOperationMetrics extends Command
{
    protected $signature = 'operations:prune-metrics';

    protected $description = 'Expire disposable operational metric buckets without changing usage or financial history';

    public function handle(Operations $operations): int
    {
        $this->line('Expired metric buckets: '.$operations->pruneExpiredMetrics());

        return self::SUCCESS;
    }
}
