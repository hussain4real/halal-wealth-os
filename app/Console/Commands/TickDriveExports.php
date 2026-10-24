<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrations\Drive\DriveExports;
use Illuminate\Console\Command;

final class TickDriveExports extends Command
{
    protected $signature = 'drive:tick';

    protected $description = 'Recover bounded durable Drive intents; live transport remains disabled';

    public function handle(DriveExports $exports): int
    {
        $exports->tick();
        $this->line('Drive scan completed. Live access remains disabled.');

        return self::SUCCESS;
    }
}
