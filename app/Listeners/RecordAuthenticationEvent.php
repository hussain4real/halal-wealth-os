<?php

namespace App\Listeners;

use App\Domain\Identity\SecurityAudit;
use App\Domain\Identity\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

class RecordAuthenticationEvent
{
    public function handle(Login|Failed|Logout|PasswordReset $event): void
    {
        $kind = match (true) {
            $event instanceof Login => SecurityEvent::Login,
            $event instanceof Failed => SecurityEvent::LoginFailed,
            $event instanceof Logout => SecurityEvent::Logout,
            $event instanceof PasswordReset => SecurityEvent::PasswordReset,
        };

        $userId = $event->user instanceof User ? $event->user->id : null;
        // A failed attempt targets this identity; it never authenticates its actor.
        app(SecurityAudit::class)->record($kind, $event instanceof Failed ? null : $userId,
            ['subject_id' => $event instanceof Failed ? $userId : null]);
    }
}
