<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final class FxService
{
    /** @param array<string, mixed> $input */
    public function record(User $owner, string $key, array $input, ?string $replacesId = null): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input, $replacesId): stdClass {
            LedgerInput::lock($owner);
            foreach (array_diff(array_keys($input), ['source_account_id', 'destination_account_id', 'source_amount', 'destination_amount', 'fee_amount', 'fee_side', 'rate_value', 'rate_source', 'observed_at', 'effective_date', 'description']) as $field) {
                LedgerInput::fail($field, 'This field cannot be submitted.');
            }
            $source = $this->account($owner, $input['source_account_id'] ?? null);
            $destination = $this->account($owner, $input['destination_account_id'] ?? null);
            if ($source->currency_code === $destination->currency_code) {
                LedgerInput::fail('destination_account_id', 'Use an ordinary transfer for accounts in the same currency.');
            }
            $original = $replacesId === null ? null : $this->owned($owner, $replacesId);
            $sent = $this->cash($source, $input['source_amount'] ?? null, 'source_amount');
            $received = $this->cash($destination, $input['destination_amount'] ?? null, 'destination_amount');
            $side = $input['fee_side'] ?? null;
            if (! in_array($side, ['source', 'destination'], true)) {
                LedgerInput::fail('fee_side', 'Choose extra source fee or withheld destination fee.');
            }
            $fee = $this->cash($side === 'source' ? $source : $destination, $input['fee_amount'] ?? null, 'fee_amount', true);
            if ($side === 'destination' && bccomp($fee->minor, $received->minor, 0) > 0) {
                LedgerInput::fail('fee_amount', 'A withheld fee cannot exceed gross destination principal.');
            }
            try {
                $rate = FxMath::rate($input['rate_value'] ?? null)->value;
            } catch (InvalidArgumentException $error) {
                LedgerInput::fail('rate_value', $error->getMessage());
            }
            $date = LedgerInput::date($input['effective_date'] ?? null);
            $observed = LedgerInput::instant($input['observed_at'] ?? null, 'observed_at');
            if (substr($observed, 0, 10) > $date) {
                LedgerInput::fail('observed_at', 'Transaction-rate observation must be on or before its UTC business date.');
            }
            $payload = ['source_account_id' => (string) $source->id, 'destination_account_id' => (string) $destination->id,
                'source_minor' => $sent->minor, 'destination_minor' => $received->minor, 'fee_minor' => $fee->minor, 'fee_side' => $side,
                'rate_value' => $rate, 'rate_source' => LedgerInput::text($input['rate_source'] ?? null, 'rate_source'), 'observed_at' => $observed,
                'effective_date' => $date, 'description' => LedgerInput::text($input['description'] ?? null, 'description'), 'replaces_id' => $original?->id];
            if ($cached = LedgerInput::cached('fx_operations', $owner, $key, $payload)) {
                return $cached;
            }
            foreach ([$source, $destination] as $account) {
                if ($account->archived_at !== null) {
                    LedgerInput::fail('source_account_id', 'New conversions require active accounts. Existing receipts and complete reversals remain available.');
                }
            }
            if ($original !== null) {
                $this->reverseGroup($owner, (string) $original->id, 'fx-correction:'.hash('sha256', $key), 'Correction of '.$original->reference, $original->effective_date);
            }
            $observation = app(FxObservationService::class)->record($owner, 'fx-rate:'.hash('sha256', $key), ['base_currency' => $source->currency_code->value,
                'quote_currency' => $destination->currency_code->value, 'value' => $rate, 'source' => $payload['rate_source'], 'observed_at' => $observed, 'effective_at' => $date.'T00:00:00Z'], 'transaction');
            $id = DB::table('fx_operations')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => $key,
                'payload_hash' => LedgerInput::hash($payload), 'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'source_account_id' => $source->id, 'destination_account_id' => $destination->id, 'source_currency' => $sent->currency->value,
                'destination_currency' => $received->currency->value, 'source_minor' => $sent->minor, 'destination_minor' => $received->minor,
                'fee_minor' => $fee->minor, 'fee_side' => $side, 'actual_rate_id' => $observation->id, 'effective_date' => $date, 'description' => $payload['description'], 'replaces_id' => $original?->id]);
            $posting = app(PostingService::class);
            $sourceClearing = $posting->systemAccountFor($owner, $sent->currency, 'clearing');
            $destinationClearing = $posting->systemAccountFor($owner, $received->currency, 'clearing');
            $context = ['fx_operation_id' => (string) $id, 'actual_rate_id' => (string) $observation->id];
            $this->member($owner, $id, $key, 'source', [['account_id' => (string) $source->id, 'minor' => bcsub('0', $sent->minor, 0)], ['account_id' => (string) $sourceClearing->id, 'minor' => $sent->minor]], $date, $payload['description'], $context);
            $this->member($owner, $id, $key, 'destination', [['account_id' => (string) $destination->id, 'minor' => $received->minor], ['account_id' => (string) $destinationClearing->id, 'minor' => bcsub('0', $received->minor, 0)]], $date, $payload['description'], $context);
            if ($fee->minor !== '0') {
                $expense = $posting->systemAccountFor($owner, $fee->currency, 'expense');
                $this->member($owner, $id, $key, 'fee', [['account_id' => (string) ($side === 'source' ? $source->id : $destination->id), 'minor' => bcsub('0', $fee->minor, 0)], ['account_id' => (string) $expense->id, 'minor' => $fee->minor]], $date, 'FX fee · '.$payload['description'], $context);
            }
            DB::table('fx_operations')->where('id', $id)->update(['status' => 'posted']);

            return $this->owned($owner, (string) $id);
        });
    }

    public function reverse(User $owner, string $id, string $key, string $reason): stdClass
    {
        return $this->reverseGroup($owner, $id, $key, $reason);
    }

    private function reverseGroup(User $owner, string $id, string $key, string $reason, ?string $effectiveDate = null): stdClass
    {
        return DB::transaction(function () use ($owner, $id, $key, $reason, $effectiveDate): stdClass {
            LedgerInput::lock($owner);
            $operation = $this->owned($owner, $id);
            $payload = ['operation_id' => (string) $operation->id, 'reason' => LedgerInput::text($reason, 'reason')];
            if ($effectiveDate !== null) {
                $payload['effective_date'] = LedgerInput::date($effectiveDate);
            }
            if ($cached = LedgerInput::cached('fx_reversals', $owner, $key, $payload)) {
                return $operation;
            }
            if ($operation->status !== 'posted') {
                LedgerInput::fail('operation', 'This complete conversion is already reversed. Open its retained history.');
            }
            DB::table('fx_reversals')->insert(['owner_id' => $owner->id, 'operation_id' => $operation->id, 'reference' => (string) Str::ulid(),
                'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($payload), 'reason' => $payload['reason']]);
            foreach (DB::table('fx_operation_journals')->where('operation_id', $operation->id)->whereIn('role', ['source', 'destination', 'fee'])->orderBy('journal_id')->get() as $member) {
                $journal = app(PostingService::class)->reverseFxMember($owner, (string) $member->journal_id, 'fx-reverse:'.hash('sha256', $key.':'.$member->role), $payload['reason'], $operation->id, $effectiveDate);
                DB::table('fx_operation_journals')->insert(['operation_id' => $operation->id, 'owner_id' => $owner->id, 'journal_id' => $journal->id, 'role' => $member->role.'_reversal']);
            }
            DB::table('fx_operations')->where('id', $operation->id)->update(['status' => 'reversed']);

            return $this->owned($owner, $id);
        });
    }

    public function owned(User $owner, string $id): stdClass
    {
        $operation = LedgerInput::owned('fx_operations', $owner, $id, true);
        $this->account($owner, (string) $operation->source_account_id);
        $this->account($owner, (string) $operation->destination_account_id);
        LedgerInput::owned('fx_observations', $owner, (string) $operation->actual_rate_id);

        return $operation;
    }

    /** @return array<string, mixed> */
    public function receipt(User $owner, string $id): array
    {
        LedgerInput::identifier($id);
        $row = DB::selectOne(<<<'SQL'
SELECT g.*,g.source_minor::text AS sent,g.destination_minor::text AS received,g.fee_minor::text AS fee,
 a.name AS source_name,b.name AS destination_name,q.value::text AS rate_value,q.source AS rate_source,q.observed_at,q.effective_at,
 r.reference AS reversal_reference,r.reason AS reversal_reason,r.created_at AS reversed_at,
 (SELECT child.id::text FROM fx_operations child WHERE child.replaces_id = g.id AND child.owner_id = g.owner_id) AS replacement_id,
 (SELECT jsonb_agg(jsonb_build_object('id',j.id::text,'reference',j.reference,'status',j.status,'role',m.role) ORDER BY j.id)
 FROM fx_operation_journals m JOIN journal_entries j ON j.id = m.journal_id WHERE m.operation_id = g.id AND m.owner_id = g.owner_id) AS journals
FROM fx_operations g JOIN accounts a ON a.id = g.source_account_id AND a.owner_id = g.owner_id
JOIN accounts b ON b.id = g.destination_account_id AND b.owner_id = g.owner_id
JOIN fx_observations q ON q.id = g.actual_rate_id AND q.owner_id = g.owner_id
LEFT JOIN fx_reversals r ON r.operation_id = g.id AND r.owner_id = g.owner_id WHERE g.id = ? AND g.owner_id = ?
SQL, [$id, $owner->id]);
        abort_unless($row !== null, 404);
        $sent = Money::fromMinor(Currency::from($row->source_currency), $row->sent);
        $received = Money::fromMinor(Currency::from($row->destination_currency), $row->received);
        $fee = Money::fromMinor($row->fee_side === 'source' ? $sent->currency : $received->currency, $row->fee);

        return ['id' => (string) $row->id, 'reference' => $row->reference, 'status' => $row->status, 'description' => $row->description,
            'effective_date' => $row->effective_date, 'recorded_at' => LedgerInput::utc($row->created_at),
            'source' => ['account_id' => (string) $row->source_account_id, 'name' => $row->source_name, 'money' => $sent->jsonSerialize()],
            'destination' => ['account_id' => (string) $row->destination_account_id, 'name' => $row->destination_name, 'money' => $received->jsonSerialize()],
            'fee' => ['side' => $row->fee_side, 'money' => $fee->jsonSerialize()],
            'actual_rate' => ['id' => (string) $row->actual_rate_id, 'value' => $row->rate_value, 'source' => $row->rate_source, 'observed_at' => LedgerInput::utc($row->observed_at), 'effective_at' => LedgerInput::utc($row->effective_at)],
            'implied_rate' => FxMath::implied($sent, $received), 'journals' => json_decode($row->journals, true, flags: JSON_THROW_ON_ERROR),
            'reversal' => $row->reversal_reference === null ? null : ['reference' => $row->reversal_reference, 'reason' => $row->reversal_reason, 'recorded_at' => LedgerInput::utc($row->reversed_at)],
            'replaces_id' => $row->replaces_id === null ? null : (string) $row->replaces_id, 'replacement_id' => $row->replacement_id];
    }

    private function account(User $owner, mixed $id): Account
    {
        $id = LedgerInput::identifier($id);
        $account = Account::query()->ownedBy($owner)->whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $account->type->contributesToNetWorth() || $account->system_role !== null) {
            LedgerInput::fail('source_account_id', 'Choose personal accounts, not internal ledger accounts.');
        }

        return $account;
    }

    private function cash(Account $account, mixed $amount, string $field, bool $zero = false): Money
    {
        try {
            $cash = Money::fromDecimal($account->currency_code, $amount);
        } catch (InvalidArgumentException $error) {
            LedgerInput::fail($field, $error->getMessage());
        }
        if (bccomp($cash->minor, '0', 0) < ($zero ? 0 : 1)) {
            LedgerInput::fail($field, $zero ? 'A fee cannot be negative.' : 'Principal must be positive.');
        }

        return $cash;
    }

    /**
     * @param list<array{account_id: string, minor: string}> $legs
     * @param array<string, string> $context */
    private function member(User $owner, int $id, string $key, string $role, array $legs, string $date, string $description, array $context): void
    {
        $journal = app(PostingService::class)->postBalanced($owner, 'fx-leg:'.hash('sha256', $key.':'.$role), $legs, mb_substr($description, 0, 180), $date, $context + ['role' => $role], $role === 'fee' ? 'expense' : 'adjustment');
        DB::table('fx_operation_journals')->insert(['operation_id' => $id, 'owner_id' => $owner->id, 'journal_id' => $journal->id, 'role' => $role]);
    }
}
