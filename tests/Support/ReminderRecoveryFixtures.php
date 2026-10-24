<?php

namespace Tests\Support;

use App\Domain\Notifications\Handoff;
use App\Domain\Notifications\ReminderMail;
use App\Domain\Notifications\ReminderMessage;
use App\Domain\Notifications\Reminders;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class ReminderRecoveryFixtures
{
    public static function seed(User $owner, User $foreign): void
    {
        Mail::fake();
        $service = app(Reminders::class);
        $schedules = [];
        foreach (['accepted', 'interrupted', 'unknown', 'retry'] as $mode) {
            $schedule = ReminderFixtures::schedule($owner, ['name' => 'Synthetic '.$mode.' reminder', 'first_due_date' => now('Asia/Qatar')->subDay()->toDateString()]);
            $schedules[] = (string) $schedule->id;
            ReminderFixtures::configure($owner, ReminderFixtures::preferences($owner, $schedule, ['schedule_ids' => $schedules]));
            $service->plan($owner);
            $notification = DB::table('reminder_notifications')->where('owner_id', $owner->id)->orderByDesc('id')->first();
            $channel = DB::table('reminder_channels')->where('notification_id', $notification->id)->where('channel', 'email')->first();
            if ($mode === 'accepted') {
                $inApp = DB::table('reminder_channels')->where('notification_id', $notification->id)->where('channel', 'in_app')->first();
                $service->dispatch((string) $inApp->id);
                $service->dispatch((string) $channel->id);
                $service->read($owner, (string) $notification->id);
            } elseif ($mode === 'interrupted') {
                DB::table('reminder_claims')->insert(['owner_id' => $owner->id, 'channel_id' => $channel->id, 'ordinal' => 1,
                    'permit_hash' => hash('sha256', 'synthetic-lost-permit'), 'destination_hash' => hash('sha256', $owner->email)]);
            } else {
                $transport = new class($mode) extends ReminderMail
                {
                    public function __construct(private readonly string $mode) {}

                    public function send(string $recipient, ReminderMessage $message): Handoff
                    {
                        return $this->mode === 'retry' ? Handoff::BeforeHandoffFailure : Handoff::Uncertain;
                    }
                };
                (new Reminders($transport))->dispatch((string) $channel->id);
            }
        }
        $service->pause($owner);
        $other = ReminderFixtures::schedule($foreign, ['first_due_date' => now('Asia/Qatar')->subDay()->toDateString()]);
        ReminderFixtures::configure($foreign, ReminderFixtures::preferences($foreign, $other));
        $service->plan($foreign);
    }
}
