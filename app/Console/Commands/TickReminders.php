<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Reminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class TickReminders extends Command
{
    protected $signature = 'reminders:tick';

    protected $description = 'Admit explicitly opted-in reminders and recover durable ID-only dispatch hints';

    public function handle(Reminders $reminders): int
    {
        $cursor = Cache::get('hwo:reminder-owner-cursor', '0');
        $report = $reminders->tick(is_string($cursor) && preg_match('/^[0-9]{1,18}$/D', $cursor) ? $cursor : '0');
        Cache::put('hwo:reminder-owner-cursor', $report['next'] ?? '0');
        $this->line('Owners scanned: '.$report['owners'].'; capacity warnings: '.$report['capacity'].'. Live reminder email remains blocked.');

        return self::SUCCESS;
    }
}
