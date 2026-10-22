<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Closure;
use stdClass;

/** Completion must persist the exact successor before the financial service returns. */
final readonly class RetainedCorrection
{
    /** @param Closure(string, string): stdClass $write */
    public function __construct(public string $receiptId, public string $token, public Closure $write) {}
}
