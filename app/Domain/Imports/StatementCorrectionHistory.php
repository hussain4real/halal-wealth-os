<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Ledger\CorrectionHistory;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\RetainedCorrection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StatementCorrectionHistory implements CorrectionHistory
{
    public function origin(User $owner, string $kind, string $id): ?array
    {
        if (! Schema::hasTable('statement_receipts')) {
            return null;
        }
        $field = $kind === 'journal' ? 'journal_id' : 'event_id';
        $row = DB::table('statement_receipts as r')->where('r.owner_id', $owner->id)
            ->crossJoin(DB::raw('LATERAL jsonb_each(r.outcomes) AS o(token, outcome)'))
            ->whereIn(DB::raw("o.outcome->>'type'"), ['created', 'replacement'])
            ->whereRaw("o.outcome->'target'->>? = ?", [$field, $id])
            ->selectRaw("r.id::text AS receipt_id, o.token, (o.outcome->'target')::text AS target")->first();

        return $row === null ? null : ['receipt_id' => $row->receipt_id, 'token' => $row->token, 'target' => json_decode($row->target, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function authorize(User $owner, string $kind, string $id, ?RetainedCorrection $completion): void
    {
        $origin = $this->origin($owner, $kind, $id);
        if ($origin === null && $completion === null) {
            return;
        }
        if ($origin === null || $completion === null || $completion->receiptId !== $origin['receipt_id'] || $completion->token !== $origin['token']
            || $this->successor($owner, $completion->receiptId, $completion->token) !== null) {
            LedgerInput::fail('journal', 'Use the retained import receipt to correct or cancel this imported entry.');
        }
    }

    public function complete(User $owner, string $kind, string $id, string $resultId, string $currency, string $outcome, ?RetainedCorrection $completion): void
    {
        if ($completion === null) {
            return;
        }
        ($completion->write)($resultId, $currency);
        $origin = $this->origin($owner, $kind, $id);
        $receipt = $this->successor($owner, $completion->receiptId, $completion->token);
        $rows = $receipt === null ? [] : json_decode($receipt->outcomes, true, flags: JSON_THROW_ON_ERROR);
        $row = $rows['1'] ?? null;
        $field = $kind === 'journal' ? 'journal_id' : 'event_id';
        $expected = $origin['target'] ?? [];
        $expected[$field] = $resultId;
        $expected['currency'] = $currency;
        if ($origin === null || $receipt === null || count($rows) !== 1 || ! is_array($row)
            || ($row['type'] ?? null) !== $outcome || ($row['original_target'] ?? null) != $origin['target'] || ($row['target'] ?? null) != $expected
            || ($row['origin'] ?? null) != ['receipt_id' => $completion->receiptId, 'token' => $completion->token]) {
            LedgerInput::fail('journal', 'The financial correction requires its exact retained import successor. Nothing was committed.');
        }
    }

    private function successor(User $owner, string $receiptId, string $token): ?\stdClass
    {
        return DB::table('statement_receipts')->where('owner_id', $owner->id)->where('corrects_receipt_id', $receiptId)->where('corrects_token', $token)->first();
    }
}
