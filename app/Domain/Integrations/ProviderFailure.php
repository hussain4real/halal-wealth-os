<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use RuntimeException;

final class ProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, public readonly int $retryAfter = 0)
    {
        parent::__construct('Provider refresh was not admitted: '.$reason.'.');
    }
}
