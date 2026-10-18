<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Money;
use JsonSerializable;

/** Opening intent and journal state observed with the account balance. */
final readonly class OpeningRequestSnapshot implements JsonSerializable
{
    public function __construct(public Money $amount, public ?string $journalId, public string $status) {}

    /** @return array{status: string, journal_id: ?string, amount: array{currency: string, minor: string, amount: string}} */
    public function jsonSerialize(): array
    {
        return ['status' => $this->status, 'journal_id' => $this->journalId, 'amount' => $this->amount->jsonSerialize()];
    }
}
