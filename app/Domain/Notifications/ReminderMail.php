<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Throwable;

class ReminderMail
{
    public function enabled(): bool
    {
        return app()->environment('testing') && Mail::getFacadeRoot() instanceof MailFake;
    }

    public function send(string $recipient, ReminderMessage $message): Handoff
    {
        if (! $this->enabled()) {
            return Handoff::BeforeHandoffFailure;
        }
        try {
            // Only the application-specific synthetic gate may reach this adapter.
            // No SMTP credentials, network call or global authentication-mail change.
            Mail::mailer('smtp')->to($recipient)->send($message);

            return Handoff::Accepted;
        } catch (Throwable) {
            return Handoff::Uncertain;
        }
    }
}
