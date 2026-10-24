<?php

namespace App\Domain\Intelligence;

final class UnavailableProvider implements ToolProvider
{
    public function available(): bool
    {
        return false;
    }

    public function propose(UntrustedInput $input, array $registry, int $outputByteLimit): string
    {
        throw new \RuntimeException('No approved provider is configured.');
    }
}
