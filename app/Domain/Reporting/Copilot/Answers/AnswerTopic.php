<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot\Answers;

enum AnswerTopic: string
{
    case Cash = 'cash';
    case Emergency = 'emergency';
    case Nigeria = 'nigeria';
    case Changes = 'changes';
    case Assessments = 'assessments';
    case Refuse = 'refuse';
    case Unsupported = 'unsupported';

    public function scope(): string
    {
        return match ($this) {
            self::Cash, self::Emergency => 'current',
            self::Nigeria, self::Changes, self::Assessments => 'selected',
            self::Refuse, self::Unsupported => 'unsupported',
        };
    }
}
