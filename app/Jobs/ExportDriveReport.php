<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Integrations\Drive\DriveExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExportDriveReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $operationId) {}

    public function handle(DriveExports $exports): void
    {
        $exports->run($this->operationId);
    }
}
