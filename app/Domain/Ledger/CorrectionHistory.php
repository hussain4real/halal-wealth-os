<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Models\User;

/** Inward contract for applications that own a financial root's correction history. */
interface CorrectionHistory
{
    /** @return array{receipt_id:string,token:string,target:array<string,mixed>}|null */
    public function origin(User $owner, string $kind, string $id): ?array;

    public function authorize(User $owner, string $kind, string $id, ?RetainedCorrection $completion): void;

    public function complete(User $owner, string $kind, string $id, string $resultId, string $currency, string $outcome, ?RetainedCorrection $completion): void;
}
