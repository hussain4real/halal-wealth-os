<?php

namespace App\Console\Commands;

use App\Support\Release\ReleaseReadiness;
use Illuminate\Console\Command;

final class CheckPrivateRelease extends Command
{
    protected $signature = 'release:check';

    protected $description = 'Check private-release safeguards without printing configuration values';

    public function handle(ReleaseReadiness $readiness): int
    {
        $report = $readiness->inspect();
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['result'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }
}
