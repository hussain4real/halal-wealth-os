<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class OperationBlocked extends HttpException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(in_array($reason, ['paused', 'policy_changed'], true) ? 409 : 429,
            'Optional work is held: '.$reason.'. Core financial features remain available.');
    }
}
