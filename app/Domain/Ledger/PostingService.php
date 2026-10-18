<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceIntent;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** One owner-serialized, composable PostgreSQL transaction for every movement. */
final class PostingService
{
    /** @param array<string, mixed> $input */
    public function record(User $owner, string $key, array $input): JournalEntry
    {
        return DB::transaction(function () use ($owner, $key, $input): JournalEntry {
            $this->lockOwner($owner);

            return $this->recordLocked($owner, $key, $input);
        });
    }

    /** @param array<string, mixed> $input */
    public function correct(User $owner, string $originalId, string $key, array $input): JournalEntry
    {
        return DB::transaction(function () use ($owner, $originalId, $key, $input): JournalEntry {
            $this->lockOwner($owner);
            $original = $this->ownedJournal($owner, $originalId);
            $this->authorizeJournalAccounts($owner, $original);
            $this->requireUngrouped($owner, $original);
            if (! in_array($original->kind, ['income', 'expense', 'transfer'], true)) {
                $this->fail('journal', 'This record can be reversed, but cannot be replaced with a new transaction.');
            }

            return $this->recordLocked($owner, $key, $input, $original);
        });
    }

    public function reverse(User $owner, string $originalId, string $key, string $reason): JournalEntry
    {
        return DB::transaction(function () use ($owner, $originalId, $key, $reason): JournalEntry {
            $this->lockOwner($owner);

            return $this->reverseLocked($owner, $this->ownedJournal($owner, $originalId), $key, $reason);
        });
    }

    public function postOpening(User $owner, string $accountId, string $key): JournalEntry
    {
        return DB::transaction(function () use ($owner, $accountId, $key): JournalEntry {
            $this->lockOwner($owner);
            $account = $this->ownedAccount($owner, $accountId);
            $intent = OpeningBalanceIntent::query()->where('account_id', $account->id)->firstOrFail();
            $payload = ['kind' => 'opening', 'account_id' => (string) $account->id, 'intent_id' => (string) $intent->id, 'minor' => $intent->amount_minor];
            if ($cached = $this->cached($owner, $key, $payload)) {
                return $cached;
            }
            $existing = DB::table('opening_balance_postings')->where('opening_intent_id', $intent->id)->value('journal_id');
            if ($existing !== null) {
                $this->fail('idempotency_key', 'This opening request is already posted. View its linked history from the account; a new request identity cannot post it again.');
            }
            $this->requireActive($account);
            $equity = $this->systemAccount($owner, $account->currency_code, 'equity');
            $legs = [['account' => $account, 'minor' => $intent->amount_minor], ...$this->splitLeg($equity, bcsub('0', $intent->amount_minor, 0))];
            $journal = $this->append($owner, $key, $payload, 'opening', $intent->created_at->format('Y-m-d'), 'Opening balance', null, $legs);
            DB::table('opening_balance_postings')->insert(['opening_intent_id' => $intent->id, 'journal_id' => $journal->id]);

            return $journal;
        });
    }

    /**
     * Exact balanced application-service port; no FX, no inferred conversion.
     *
     * @param  list<array{account_id: string, minor: string}>  $movements
     * @param  array<string, mixed>  $context
     */
    public function postBalanced(User $owner, string $key, array $movements, string $description, ?string $effectiveDate = null, array $context = [], string $kind = 'adjustment'): JournalEntry
    {
        return DB::transaction(function () use ($owner, $key, $movements, $description, $effectiveDate, $context, $kind): JournalEntry {
            $this->lockOwner($owner);
            if ($effectiveDate !== null) {
                LedgerInput::date($effectiveDate);
            }
            if (! in_array($kind, ['adjustment', 'expense', 'income'], true)) {
                $this->fail('kind', 'Unsupported balanced service operation.');
            }
            $description = $this->text($description, 'description');
            $legs = [];
            foreach ($movements as $movement) {
                $account = $this->ownedAccount($owner, $movement['account_id']);
                $legs[] = ['account' => $account, 'minor' => Money::fromMinor($account->currency_code, $movement['minor'])->minor];
            }
            $canonical = array_map(fn (array $leg): array => ['account_id' => (string) $leg['account']->id, 'minor' => $leg['minor']], $legs);
            usort($canonical, fn (array $a, array $b): int => [$a['account_id'], $a['minor']] <=> [$b['account_id'], $b['minor']]);
            $payload = ['kind' => $kind, 'movements' => $canonical, 'description' => $description];
            if ($effectiveDate !== null) {
                $payload['effective_date'] = $effectiveDate;
            }
            if ($context !== []) {
                $payload['context'] = $context;
            }
            if ($cached = $this->cached($owner, $key, $payload)) {
                return $cached;
            }
            foreach ($legs as $leg) {
                $this->requireActive($leg['account']);
            }

            return $this->append($owner, $key, $payload, $kind, $effectiveDate ?? now()->toDateString(), $description, null, $legs);
        });
    }

    public function systemAccountFor(User $owner, Currency $currency, string $role): Account
    {
        return DB::transaction(function () use ($owner, $currency, $role): Account {
            $this->lockOwner($owner);
            if (! in_array($role, ['clearing', 'equity', 'expense', 'income'], true)) {
                $this->fail('offset', 'Unsupported internal account role.');
            }

            return $this->systemAccount($owner, $currency, $role);
        });
    }

    /** Protected application-service port: the retained root cancellation must exist in this transaction. */
    public function reverseInvestmentMember(User $owner, string $originalId, string $key, string $reason, int $reversalEventId): JournalEntry
    {
        return DB::transaction(function () use ($owner, $originalId, $key, $reason, $reversalEventId): JournalEntry {
            $this->lockOwner($owner);
            $original = $this->ownedJournal($owner, $originalId);
            $member = DB::table('investment_event_journals')->where('owner_id', $owner->id)->where('journal_id', $original->id)->first();
            $root = DB::table('investment_events')->where('owner_id', $owner->id)->where('id', $reversalEventId)->first();
            abort_unless($member !== null && $root !== null && $root->kind === 'reversal' && $root->reverses_id === $member->event_id, 404);

            return $this->reverseLocked($owner, $original, $key, $reason, effectiveDate: $root->effective_date, investmentEventId: $reversalEventId);
        });
    }

    /** Only the FX coordinator can complete this under its outer transaction. */
    public function reverseFxMember(User $owner, string $originalId, string $key, string $reason, int $operationId, ?string $effectiveDate = null): JournalEntry
    {
        return DB::transaction(function () use ($owner, $originalId, $key, $reason, $operationId, $effectiveDate): JournalEntry {
            $this->lockOwner($owner);
            $original = $this->ownedJournal($owner, $originalId);
            $member = DB::table('fx_operation_journals')->where('owner_id', $owner->id)->where('journal_id', $original->id)->first();
            abort_unless($member !== null && $member->operation_id === $operationId && in_array($member->role, ['source', 'destination', 'fee'], true), 404);

            return $this->reverseLocked($owner, $original, $key, $reason, $operationId, $effectiveDate);
        });
    }

    /** @param array<string, mixed> $input */
    private function recordLocked(User $owner, string $key, array $input, ?JournalEntry $replaces = null): JournalEntry
    {
        $unknown = array_diff(array_keys($input), ['kind', 'account_id', 'destination_account_id', 'amount', 'effective_date', 'description', 'receipt_reference']);
        if ($unknown !== []) {
            $this->fail((string) reset($unknown), 'This field cannot be submitted.');
        }
        $kind = $input['kind'] ?? null;
        if (! in_array($kind, ['income', 'expense', 'transfer'], true)) {
            $this->fail('kind', 'Choose income, expense or transfer.');
        }
        $account = $this->ownedAccount($owner, $this->stringId($input['account_id'] ?? null, 'account_id'));
        if (! $account->type->contributesToNetWorth() || $account->system_role !== null) {
            $this->fail('account_id', 'Choose a personal account, not an internal ledger account.');
        }
        $destination = null;
        $destinationId = $input['destination_account_id'] ?? null;
        if ($kind === 'transfer') {
            $destination = $this->ownedAccount($owner, $this->stringId($destinationId, 'destination_account_id'));
            if ($destination->id === $account->id || ! $destination->type->contributesToNetWorth() || $destination->system_role !== null) {
                $this->fail('destination_account_id', 'Choose a different personal account.');
            }
            if ($destination->currency_code !== $account->currency_code) {
                $this->fail('destination_account_id', 'A transfer requires two accounts in the same currency.');
            }
        } elseif ($destinationId !== null && $destinationId !== '') {
            $this->fail('destination_account_id', 'A destination is only used for a transfer.');
        }
        try {
            $amount = Money::fromDecimal($account->currency_code, $input['amount'] ?? null);
        } catch (InvalidArgumentException $error) {
            $this->fail('amount', $error->getMessage());
        }
        if (bccomp($amount->minor, '0', 0) <= 0) {
            $this->fail('amount', 'Enter an amount greater than zero. Use a reversal to correct history.');
        }
        $date = $input['effective_date'] ?? null;
        $parsedDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! $parsedDate instanceof DateTimeImmutable || $parsedDate->format('Y-m-d') !== $date || $date < '0001-01-01' || $date > now()->toDateString()) {
            $this->fail('effective_date', 'Choose a valid date on or before today. Future plans are not posted transactions.');
        }
        $description = $this->text($input['description'] ?? null, 'description');
        $receiptInput = $input['receipt_reference'] ?? null;
        $receipt = $receiptInput === null || $receiptInput === '' ? null : $this->text($receiptInput, 'receipt_reference');
        $payload = ['kind' => $kind, 'account_id' => (string) $account->id, 'destination_account_id' => $destination === null ? null : (string) $destination->id,
            'amount_minor' => $amount->minor, 'currency' => $amount->currency->value, 'effective_date' => $date, 'description' => $description, 'receipt_reference' => $receipt,
            'replaces_journal_id' => $replaces === null ? null : (string) $replaces->id];
        if ($cached = $this->cached($owner, $key, $payload)) {
            return $cached;
        }
        $this->requireActive($account);
        if ($destination !== null) {
            $this->requireActive($destination);
        }
        if ($replaces !== null) {
            $this->reverseLocked($owner, $replaces, 'correction-reversal:'.hash('sha256', $key), 'Correction of '.$replaces->reference, effectiveDate: $replaces->effective_date->format('Y-m-d'));
        }
        $counter = $destination ?? $this->systemAccount($owner, $account->currency_code, $kind);
        $signed = $kind === 'income' ? $amount->minor : bcsub('0', $amount->minor, 0);
        $legs = [['account' => $account, 'minor' => $signed], ['account' => $counter, 'minor' => bcsub('0', $signed, 0)]];

        return $this->append($owner, $key, $payload, $kind, $date, $description, $receipt, $legs, replaces: $replaces?->id);
    }

    private function reverseLocked(User $owner, JournalEntry $original, string $key, string $reason, ?int $fxOperationId = null, ?string $effectiveDate = null, ?int $investmentEventId = null): JournalEntry
    {
        $accounts = $this->authorizeJournalAccounts($owner, $original);
        if ($fxOperationId === null && $investmentEventId === null) {
            $this->requireUngrouped($owner, $original);
        }
        $reason = $this->text($reason, 'reason');
        $payload = ['kind' => 'reversal', 'original_id' => (string) $original->id, 'reason' => $reason];
        if ($effectiveDate !== null) {
            $payload['effective_date'] = LedgerInput::date($effectiveDate);
        }
        if ($investmentEventId !== null) {
            $payload['context'] = ['investment_event_id' => (string) $investmentEventId];
        }
        if ($cached = $this->cached($owner, $key, $payload)) {
            return $cached;
        }
        if ($original->status !== 'posted' || $original->kind === 'reversal') {
            $this->fail('journal', 'This transaction has already been reversed or is not eligible for reversal.');
        }
        $legs = [];
        foreach ($original->postings as $posting) {
            array_push($legs, ...$this->splitLeg($accounts[$posting->account_id], bcsub('0', $posting->amount_minor, 0)));
        }
        $journal = $this->append($owner, $key, $payload, 'reversal', $effectiveDate ?? now('UTC')->toDateString(), $reason, null, $legs, reverses: $original->id);
        DB::table('journal_entries')->where('id', $original->id)->update(['status' => 'reversed']);

        return $journal;
    }

    /** @return array<int, Account> */
    private function authorizeJournalAccounts(User $owner, JournalEntry $journal): array
    {
        $accounts = [];
        foreach ($journal->postings->sortBy('account_id') as $posting) {
            $accounts[$posting->account_id] = $this->ownedAccount($owner, (string) $posting->account_id);
        }

        return $accounts;
    }

    private function lockOwner(User $owner): void
    {
        User::query()->whereKey($owner->id)->firstOrFail();
        // Retained through the outermost transaction. Collisions only serialize.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['hwo:posting:owner:'.$owner->id]);
    }

    private function ownedAccount(User $owner, string $id): Account
    {
        return Account::query()->ownedBy($owner)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function ownedJournal(User $owner, string $id): JournalEntry
    {
        return JournalEntry::query()->where('owner_id', $owner->id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function requireActive(Account $account): void
    {
        if ($account->archived_at !== null) {
            $this->fail('account_id', 'This account is archived. Choose an active account. Existing transactions can still be reversed.');
        }
    }

    private function requireUngrouped(User $owner, JournalEntry $journal): void
    {
        if (Schema::hasTable('investment_event_journals') && DB::table('investment_event_journals')->where('owner_id', $owner->id)->where('journal_id', $journal->id)->exists()) {
            $this->fail('journal', 'This cash journal belongs to an investment event. Open its investment receipt to reverse or correct cash and holdings together.');
        }
        if (DB::table('fx_operation_journals')->where('owner_id', $owner->id)->where('journal_id', $journal->id)->exists()) {
            $this->fail('journal', 'This journal belongs to an FX conversion. Open its conversion receipt to reverse or correct the complete operation.');
        }
    }

    private function systemAccount(User $owner, Currency $currency, string $role): Account
    {
        $account = Account::query()->ownedBy($owner)->where('currency_code', $currency->value)->where('system_role', $role)->first();
        if ($account !== null) {
            $this->requireActive($account);

            return $account;
        }
        $account = new Account(['name' => ucfirst($role).' · '.$currency->value, 'type' => AccountType::from('internal_'.$role), 'currency_code' => $currency]);
        $account->owner()->associate($owner);
        $account->forceFill(['system_role' => $role]);
        $account->save();

        return $account;
    }

    /** @param array<string, mixed> $payload */
    private function cached(User $owner, string $key, array $payload): ?JournalEntry
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $key)) {
            $this->fail('idempotency_key', 'The request identity is invalid. Reload the entry form before posting.');
        }
        $existing = JournalEntry::query()->where('owner_id', $owner->id)->where('idempotency_key', $key)->first();
        if ($existing !== null && ($existing->payload_hash !== $this->fingerprint($payload) || $existing->status === 'pending')) {
            $this->fail('idempotency_key', 'This request identity already has different details or is still pending. Check history before starting a new transaction.');
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{account: Account, minor: string}>  $legs
     */
    private function append(User $owner, string $key, array $payload, string $kind, string $date, string $description, ?string $receipt, array $legs, ?int $reverses = null, ?int $replaces = null): JournalEntry
    {
        $sum = '0';
        $currency = $legs[0]['account']->currency_code ?? null;
        foreach ($legs as $leg) {
            $money = Money::fromMinor($leg['account']->currency_code, $leg['minor']);
            if ($leg['account']->owner_id !== $owner->id || $money->currency !== $currency) {
                $this->fail('journal', 'Every leg must be owned and use one currency. No conversion is performed.');
            }
            $sum = bcadd($sum, $money->minor, 0);
        }
        if (count($legs) < 2 || bccomp($sum, '0', 0) !== 0) {
            $this->fail('journal', 'Journal legs must balance exactly. Nothing was posted.');
        }
        $journal = new JournalEntry;
        $journal->forceFill(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => $key,
            'payload_hash' => $this->fingerprint($payload), 'request_payload' => $payload, 'kind' => $kind, 'status' => 'pending', 'effective_date' => $date,
            'description' => $description, 'receipt_reference' => $receipt, 'reverses_journal_id' => $reverses, 'replaces_journal_id' => $replaces])->save();
        foreach ($legs as $leg) {
            DB::table('postings')->insert(['journal_id' => $journal->id, 'owner_id' => $owner->id, 'account_id' => $leg['account']->id,
                'currency_code' => $leg['account']->currency_code->value, 'amount_minor' => $leg['minor']]);
        }
        DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'posted', 'posted_at' => now()]);
        DB::afterCommit(static fn () => event(new JournalPosted($journal->id, $owner->id)));

        return $journal->refresh();
    }

    /**
     * @param  numeric-string  $minor
     * @return list<array{account: Account, minor: string}>
     */
    private function splitLeg(Account $account, string $minor): array
    {
        // MIN is valid money. Its opposite needs two individually valid legs.
        if (bccomp($minor, Money::MAX_MINOR, 0) > 0) {
            return [['account' => $account, 'minor' => Money::MAX_MINOR], ['account' => $account, 'minor' => bcsub($minor, Money::MAX_MINOR, 0)]];
        }

        return [['account' => $account, 'minor' => $minor]];
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function stringId(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $value)) {
            $this->fail($field, 'Choose an account.');
        }

        return $value;
    }

    private function text(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 180) {
            $this->fail($field, 'Enter between 1 and 180 characters.');
        }

        return trim($value);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
