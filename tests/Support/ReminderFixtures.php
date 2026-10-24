<?php

namespace Tests\Support;

use App\Domain\Notifications\ReminderPolicy;
use App\Domain\Notifications\Reminders;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReminderFixtures
{
    public static function schedule(User $owner, array $changes = []): object
    {
        $category = BudgetFixtures::category($owner, ['direction' => 'income']);

        return BudgetFixtures::rule($owner, $category, [...['first_due_date' => '2026-10-20', 'amount' => '11.00', 'standing_order' => '1'], ...$changes]);
    }

    public static function preferences(User $owner, ?object $schedule = null, array $changes = []): array
    {
        $defaults = ReminderPolicy::defaults();
        $defaults['rules']['contribution'] = ['in_app' => true, 'email' => true];
        $defaults['schedule_ids'] = $schedule === null ? [] : [(string) $schedule->id];
        $defaults['grace_days'] = 0;
        $defaults['quiet_enabled'] = false;
        $defaults['send_after'] = '00:00';
        $defaults['external_paused'] = false;

        return [...$defaults, ...$changes];
    }

    public static function configure(User $owner, array $data): object
    {
        $service = app(Reminders::class);

        return $service->configure($owner, 'reminder-'.Str::ulid(), $service->settings($owner)['token'], $data);
    }

    public static function channel(User $owner, string $channel): string
    {
        return (string) DB::table('reminder_channels')->where('owner_id', $owner->id)->where('channel', $channel)->value('id');
    }

    public static function wire(array $data, string $prior = ''): array
    {
        $wire = ['prior' => $prior, 'idempotency_key' => 'reminder-'.Str::ulid(), 'schedules' => implode(',', $data['schedule_ids'])];
        foreach (['timezone', 'send_after', 'quiet_start', 'quiet_end', 'grace_days', 'detail'] as $key) {
            $wire[$key] = (string) $data[$key];
        }
        foreach (['quiet_enabled', 'external_paused'] as $key) {
            $wire[$key] = $data[$key] ? '1' : '0';
        }
        foreach ($data['rules'] as $rule => $channels) {
            foreach ($channels as $channel => $value) {
                $wire[$rule.'_'.$channel] = $value ? '1' : '0';
            }
        }

        return $wire;
    }
}
