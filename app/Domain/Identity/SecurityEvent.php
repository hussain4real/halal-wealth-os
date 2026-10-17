<?php

namespace App\Domain\Identity;

enum SecurityEvent: string
{
    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case PasswordReset = 'auth.password_reset';
    case ProfileUpdated = 'identity.profile_updated';
    case OwnerOnboarded = 'identity.owner_onboarded';
}
