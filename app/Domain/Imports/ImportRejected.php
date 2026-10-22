<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use RuntimeException;

/** Raised inside the financial transaction; its rollback is definitive. */
final class ImportRejected extends RuntimeException
{
    /** @param array<string,list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The selected import was rejected without a financial commit.');
    }
}
