<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

interface ToolProvider
{
    public function available(): bool;

    /** Propose ONE read-only query as JSON, never execute it. Registry is trusted,
     * input text is untrusted. No owner, credential or raw financial records pass here.
     * Implementations must bound transport/time and refuse output beyond the ceiling.
     *
     * @param list<array<string,mixed>> $registry */
    public function propose(UntrustedInput $input, array $registry, int $outputByteLimit): string;
}
