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
        // Failed attempts and forgotten-password resets identify a target,
        // without establishing an authenticated requester as the actor.
        $hasUnauthenticatedActor = $event instanceof Failed || $event instanceof PasswordReset;
        app(SecurityAudit::class)->record($kind, $hasUnauthenticatedActor ? null : $userId,
            ['subject_id' => $hasUnauthenticatedActor ? $userId : null]);
    }
}
