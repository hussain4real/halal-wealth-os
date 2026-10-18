<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/** Rebuildable downstream readers use the durable journal ID, never post again. */
final readonly class JournalPosted
{
    public function __construct(public int $journalId, public int $ownerId) {}
}
