<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

enum Handoff: string
{
    case Accepted = 'mock_smtp';
    case BeforeHandoffFailure = 'retryable';
    case Uncertain = 'uncertain';

    public function code(): string
    {
        return match ($this) {
            self::Accepted => 'simulated_acceptance', self::BeforeHandoffFailure => 'proved_no_handoff', self::Uncertain => 'handoff_unknown',
        };
    }
}
