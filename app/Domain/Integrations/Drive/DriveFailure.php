<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drive;

use RuntimeException;

final class DriveFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Drive operation requires review: '.$reason);
    }
}
