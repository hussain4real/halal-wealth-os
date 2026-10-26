<?php

declare(strict_types=1);

namespace App\Domain\Operations;

enum DriveAction: string
{
    case Connect = 'connect';
    case Prepare = 'prepare';
    case Review = 'review';
    case Confirm = 'confirm';
    case Run = 'run';
    case Revoke = 'revoke';

    public function calls(): int
    {
        return match ($this) {
            self::Connect => 3, self::Prepare, self::Review => 4,
            self::Confirm => 5, self::Run => 8, self::Revoke => 1,
        };
    }
}
