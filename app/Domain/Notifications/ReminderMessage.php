<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ReminderMessage extends Mailable
{
    public function __construct(public string $safeSubject, public string $safeBody, public string $identity) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->safeSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.reminder', with: ['body' => $this->safeBody, 'link' => url('/notifications')]);
    }
}
