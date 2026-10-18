<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final class ReconciliationService
{
    public function record(User $owner, string $key, string $accountId, mixed $statementAmount, string $cutoff, ?string $supersedesId = null): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $accountId, $statementAmount, $cutoff, $supersedesId): stdClass {
            LedgerInput::lock($owner);
            $account = LedgerInput::owned('accounts', $owner, $accountId);
            if ($account->system_role !== null || ! AccountType::from($account->type)->contributesToNetWorth()) {
                LedgerInput::fail('account_id', 'Choose a personal statement account.');
            }
            $currency = Currency::from($account->currency_code);
            try {
                $statement = Money::fromDecimal($currency, $statementAmount);
            } catch (InvalidArgumentException $error) {
                LedgerInput::fail('statement_amount', $error->getMessage());
            }
            $prior = $supersedesId === null ? null : LedgerInput::owned('reconciliation_sessions', $owner, $supersedesId);
            if ($prior !== null && (string) $prior->account_id !== $accountId) {
                LedgerInput::fail('supersedes_id', 'A new comparison must retain the original owned account.');
            }
            $payload = ['account_id' => $accountId, 'statement_minor' => $statement->minor, 'cutoff' => LedgerInput::instant($cutoff, 'cutoff'), 'supersedes_id' => $prior?->id];
            if ($cached = LedgerInput::cached('reconciliation_sessions', $owner, $key, $payload)) {
                return $cached;
            }
            $evidence = app(FinancialSnapshot::class)->capture($owner, $currency, $cutoff, $accountId);

            return $this->persist($owner, $key, $payload, $currency, $evidence);
        });
    }

    public function adjust(User $owner, string $sessionId, string $key, string $offset, string $reason): stdClass
    {
        return DB::transaction(function () use ($owner, $sessionId, $key, $offset, $reason): stdClass {
            LedgerInput::lock($owner);
            $session = LedgerInput::owned('reconciliation_sessions', $owner, $sessionId, true);
            $account = LedgerInput::owned('accounts', $owner, (string) $session->account_id, true);
            $payload = ['session_id' => $sessionId, 'offset' => $offset, 'reason' => LedgerInput::text($reason, 'reason')];
            if ($offset !== 'equity') {
                LedgerInput::fail('offset', 'Explicitly choose the equity correction offset; this is not income or expense.');
            }
            if ($cached = LedgerInput::cached('reconciliation_adjustments', $owner, $key, $payload)) {
                return LedgerInput::owned('reconciliation_sessions', $owner, (string) $cached->result_session_id);
            }
            if (DB::table('reconciliation_adjustments')->where('original_session_id', $session->id)->exists()) {
                LedgerInput::fail('session', 'This comparison already has an adjustment. Open its linked result or save a fresh comparison.');
            }
            if ($session->status !== 'difference' || $session->difference === null) {
                LedgerInput::fail('session', 'Only a known nonzero difference can be adjusted.');
            }
            $currency = Currency::from($session->currency);
            $cutoff = LedgerInput::utc($session->cutoff);
            $snapshot = app(FinancialSnapshot::class)->capture($owner, $currency, $cutoff, (string) $account->id);
            if (FinancialSnapshot::fingerprint($snapshot) !== $session->evidence_hash) {
                LedgerInput::fail('session', 'Ledger evidence changed after this comparison. Save a new comparison before posting an adjustment.');
            }
            try {
                $difference = Money::fromMinor($currency, (string) $session->difference);
            } catch (InvalidArgumentException) {
                LedgerInput::fail('session', 'This exact difference exceeds one supported cash adjustment. Review its contributing history.');
            }
            $posting = app(PostingService::class);
            $equity = $posting->systemAccountFor($owner, $currency, 'equity');
            $legs = [...FxMath::legs((string) $account->id, $difference->minor), ...FxMath::legs((string) $equity->id, bcsub('0', $difference->minor, 0))];
            $journal = $posting->postBalanced($owner, 'rec-adjust:'.hash('sha256', $key), $legs, $payload['reason'], substr($cutoff, 0, 10), ['reconciliation_id' => $sessionId, 'offset' => $offset]);
            $evidence = app(FinancialSnapshot::class)->capture($owner, $currency, $cutoff, (string) $account->id);
            $resultPayload = ['account_id' => (string) $account->id, 'statement_minor' => (string) $session->statement_minor, 'cutoff' => $cutoff, 'supersedes_id' => $session->id];
            $result = $this->persist($owner, 'rec-result:'.hash('sha256', $key), $resultPayload, $currency, $evidence, $journal->id);
            DB::table('reconciliation_adjustments')->insert(['owner_id' => $owner->id, 'original_session_id' => $session->id, 'result_session_id' => $result->id,
                'journal_id' => $journal->id, 'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($payload), 'reason' => $payload['reason'], 'offset_role' => $offset]);

            return $result;
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $evidence */
    private function persist(User $owner, string $key, array $payload, Currency $currency, array $evidence, ?int $adjustmentId = null): stdClass
    {
        $native = $evidence['components'][0]['native']['minor'] ?? null;
        $difference = $native === null ? null : bcsub($payload['statement_minor'], $native, 0);
        $id = DB::table('reconciliation_sessions')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => $key,
            'payload_hash' => LedgerInput::hash($payload), 'account_id' => $payload['account_id'], 'currency' => $currency->value, 'statement_minor' => $payload['statement_minor'],
            'cutoff' => $payload['cutoff'], 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'evidence_hash' => FinancialSnapshot::fingerprint($evidence),
            'ledger_minor' => $native, 'difference' => $difference, 'status' => $difference === null ? 'unavailable' : ($difference === '0' ? 'matched' : 'difference'),
            'supersedes_id' => $payload['supersedes_id'], 'adjustment_journal_id' => $adjustmentId]);

        return LedgerInput::owned('reconciliation_sessions', $owner, (string) $id);
    }
}
