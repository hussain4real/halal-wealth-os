<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notifications\Reminders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $channelId) {}

    public function handle(Reminders $reminders): void
    {
        $reminders->dispatch($this->channelId);
    }
}
