<?php

declare(strict_types=1);

namespace App\Domain\Shariah;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\PostingService;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\ExactDecimal;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final class PurificationService
{
    public const OBLIGATION_FIELDS = ['context', 'distribution_id', 'supersedes_id', 'assessment_id', 'method_reference', 'method_version', 'source_instruction', 'base_kind', 'custom_base', 'base_explanation', 'calculation', 'rate_percent', 'fixed_amount', 'reason'];

    public const PAYMENT_FIELDS = ['mode', 'payment_reference', 'journal_id', 'account_id', 'amount', 'effective_date', 'description', 'evidence', 'reason'];

    public function context(User $owner, stdClass $distribution): string
    {
        $watermark = DB::table('investment_events')->where('owner_id', $owner->id)->where('sort_id', $distribution->sort_id)->max('id');

        return hash_hmac('sha256', json_encode([$owner->id, (string) $distribution->id, (string) $watermark], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string,mixed> $input */
    public function obligation(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, self::OBLIGATION_FIELDS);
            if ($row = LedgerInput::cached('purification_obligations', $owner, $key, $input)) {
                return $row;
            }
            $d = LedgerInput::owned('investment_events', $owner, LedgerInput::identifier($input['distribution_id'] ?? null));
            $this->currentDistribution($d);
            if (! is_string($input['context'] ?? null) || ! hash_equals($this->context($owner, $d), $input['context'])) {
                LedgerInput::fail('context', 'Distribution evidence changed. Review its history and reload before a new instruction. An exact saved retry can still recover.');
            }
            $prior = ($input['supersedes_id'] ?? '') === '' ? null : LedgerInput::owned('purification_obligations', $owner, LedgerInput::identifier($input['supersedes_id']));
            $method = LedgerInput::text($input['method_reference'] ?? null, 'method_reference');
            if ($prior !== null && ($prior->distribution_lineage !== $d->sort_id || $prior->method_reference !== $method || DB::table('purification_obligations')->where('supersedes_id', $prior->id)->exists())) {
                LedgerInput::fail('supersedes_id', 'Revise the latest version of this distribution and stable method reference. Earlier versions remain retained.');
            }
            if ($prior === null && DB::table('purification_obligations')->where('owner_id', $owner->id)->where('distribution_lineage', $d->sort_id)->where('method_reference', $method)->exists()) {
                LedgerInput::fail('method_reference', 'This distribution and method already have an obligation. Open its receipt to revise it; a new request key cannot create another copy.');
            }
            $assessment = ($input['assessment_id'] ?? '') === '' ? null : LedgerInput::owned('shariah_assessments', $owner, LedgerInput::identifier($input['assessment_id']));
            if ($assessment !== null && ($assessment->asset_id !== $d->asset_id || $assessment->provenance !== 'source_claim')) {
                LedgerInput::fail('assessment_id', 'Choose an attributed source assessment for the exact distribution asset reference.');
            }
            $currency = Currency::from($d->currency);
            $baseKind = $input['base_kind'] ?? null;
            if (! in_array($baseKind, ['gross', 'net', 'custom', 'missing'], true)) {
                LedgerInput::fail('base_kind', 'Choose the sourced income base or mark it missing.');
            }
            $explanation = $this->optional($input['base_explanation'] ?? '', 'base_explanation', 180);
            $custom = $input['custom_base'] ?? '';
            if ($baseKind !== 'custom' && $custom !== '') {
                LedgerInput::fail('custom_base', 'Only a separately sourced custom income base accepts an entered amount.');
            }
            $base = match ($baseKind) {
                'gross' => PortfolioInput::integer($d->amount_minor),
                'net' => bcsub(bcsub(PortfolioInput::integer($d->amount_minor), PortfolioInput::integer($d->fee_minor), 0), PortfolioInput::integer($d->tax_minor), 0),
                'custom' => $custom === '' ? null : $this->amount($currency, $custom, 'custom_base'),
                default => null,
            };
            if ($base !== null && bccomp($base, '0', 0) < 0) {
                LedgerInput::fail('base_kind', 'A sourced income base cannot be negative. Review the distribution evidence.');
            }
            if ($baseKind === 'custom' && $base !== null && $explanation === null) {
                LedgerInput::fail('base_explanation', 'Explain where this applicable income base comes from.');
            }
            $calculation = $input['calculation'] ?? null;
            if (! in_array($calculation, ['percentage', 'fixed', 'missing'], true)) {
                LedgerInput::fail('calculation', 'Choose the sourced percentage, fixed amount or missing instructions.');
            }
            $rawRate = $input['rate_percent'] ?? '';
            $rawFixed = $input['fixed_amount'] ?? '';
            if (($calculation !== 'percentage' && $rawRate !== '') || ($calculation !== 'fixed' && $rawFixed !== '')) {
                LedgerInput::fail('calculation', 'Retain only the value for the chosen calculation method.');
            }
            $rate = null;
            if ($rawRate !== '') {
                try {
                    $rate = ExactDecimal::fromString($rawRate)->value;
                    if (bccomp($rate, '0', 12) < 0) {
                        throw new InvalidArgumentException('A sourced percentage must be nonnegative.');
                    }
                } catch (InvalidArgumentException $error) {
                    LedgerInput::fail('rate_percent', $error->getMessage());
                }
            }
            $fixed = $rawFixed === '' ? null : $this->amount($currency, $rawFixed, 'fixed_amount');
            $version = $this->optional($input['method_version'] ?? '', 'method_version', 180);
            $instruction = $this->optional($input['source_instruction'] ?? '', 'source_instruction', 2000);
            $known = $assessment !== null && json_decode($assessment->evidence_refs, true, flags: JSON_THROW_ON_ERROR) !== [] && $assessment->methodology_version !== ''
                && $version !== null && $instruction !== null && $base !== null && ($calculation === 'percentage' ? $rate !== null : ($calculation === 'fixed' && $fixed !== null));
            try {
                $due = $known ? ($calculation === 'fixed' ? $fixed : PurificationMath::percentage($currency, $base, $rate)->minor) : null;
            } catch (InvalidArgumentException $error) {
                LedgerInput::fail('rate_percent', $error->getMessage());
            }

            return $this->insert('purification_obligations', $owner, $key, $input, [
                'series_id' => $prior->series_id ?? (string) Str::ulid(), 'version' => $prior === null ? 1 : $prior->version + 1, 'supersedes_id' => $prior?->id,
                'distribution_id' => $d->id, 'distribution_lineage' => $d->sort_id, 'currency' => $d->currency, 'assessment_id' => $assessment?->id,
                'method_reference' => $method, 'method_version' => $version, 'source_instruction' => $instruction, 'base_kind' => $baseKind, 'base_minor' => $base,
                'base_explanation' => $explanation, 'calculation' => $calculation, 'rate_percent' => $rate, 'fixed_minor' => $fixed, 'due_minor' => $due,
            ]);
        });
    }

    /** @param array<string,mixed> $input */
    public function payment(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, self::PAYMENT_FIELDS);
            if ($row = LedgerInput::cached('purification_payments', $owner, $key, $input)) {
                return $row;
            }
            $reference = LedgerInput::text($input['payment_reference'] ?? null, 'payment_reference');
            if (DB::table('purification_payments')->where('owner_id', $owner->id)->where('payment_reference', $reference)->exists()) {
                LedgerInput::fail('payment_reference', 'This charity payment reference is already recorded. Check its saved receipt before recording anything again.');
            }
            $mode = $input['mode'] ?? null;
            if ($mode === 'record') {
                if (($input['journal_id'] ?? '') !== '') {
                    LedgerInput::fail('journal_id', 'Link an existing journal instead of recording it again.');
                }
                $journal = app(PostingService::class)->record($owner, 'purification:'.hash('sha256', $key), [
                    'kind' => 'expense', 'account_id' => $input['account_id'] ?? null, 'amount' => $input['amount'] ?? null,
                    'effective_date' => $input['effective_date'] ?? null, 'description' => $input['description'] ?? null, 'receipt_reference' => $reference,
                ]);
                $journalId = (string) $journal->id;
            } elseif ($mode === 'link') {
                foreach (['account_id', 'amount', 'effective_date', 'description'] as $field) {
                    if (($input[$field] ?? '') !== '') {
                        LedgerInput::fail($field, 'Linking an existing journal accepts its retained financial details, without a new debit.');
                    }
                }
                $journalId = LedgerInput::identifier($input['journal_id'] ?? null);
            } else {
                LedgerInput::fail('mode', 'Record a payment already made, or link an existing expense journal.');
            }
            $details = $this->eligibleJournal($owner, $journalId);
            if (DB::table('purification_payments')->where('journal_id', $journalId)->exists()) {
                LedgerInput::fail('journal_id', 'This actual journal already has charity attribution. Open its saved payment record.');
            }

            return $this->insert('purification_payments', $owner, $key, $input, [...$details, 'journal_id' => $journalId, 'payment_reference' => $reference,
                'evidence' => LedgerInput::text($input['evidence'] ?? null, 'evidence')]);
        });
    }

    /** @param array<string,mixed> $input */
    public function allocate(User $owner, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['obligation_id', 'payment_id', 'amount', 'reason']);
            if ($row = LedgerInput::cached('purification_allocations', $owner, $key, $input)) {
                return $row;
            }
            $o = LedgerInput::owned('purification_obligations', $owner, LedgerInput::identifier($input['obligation_id'] ?? null));
            $p = LedgerInput::owned('purification_payments', $owner, LedgerInput::identifier($input['payment_id'] ?? null));
            if ($o->currency !== $p->currency) {
                LedgerInput::fail('payment_id', 'Use a payment in this obligation’s native currency. Cross-currency allocation is unavailable.');
            }
            $this->currentDistribution(LedgerInput::owned('investment_events', $owner, (string) $o->distribution_id));
            if (DB::table('purification_obligations')->where('supersedes_id', $o->id)->exists()) {
                LedgerInput::fail('obligation_id', 'Allocate to the latest obligation version after reviewing its corrected amount.');
            }
            if ($o->due_minor === null) {
                LedgerInput::fail('obligation_id', 'Instructions or applicable income base are missing. This obligation remains pending.');
            }
            $this->eligibleJournal($owner, (string) $p->journal_id);
            $amount = $this->amount(Currency::from($o->currency), $input['amount'] ?? null, 'amount');
            $paid = app(PurificationRead::class)->paid($owner, $o->series_id);
            $used = app(PurificationRead::class)->used($owner, (string) $p->id);
            if (bccomp($amount, '0', 0) <= 0 || bccomp(bcadd($used, $amount, 0), PortfolioInput::integer($p->amount_minor), 0) > 0 || bccomp(bcadd($paid, $amount, 0), PortfolioInput::integer($o->due_minor), 0) > 0) {
                LedgerInput::fail('amount', 'Allocate a positive amount within both the actual payment’s unused amount and the obligation’s current outstanding amount.');
            }

            return $this->insert('purification_allocations', $owner, $key, $input, ['obligation_id' => $o->id, 'payment_id' => $p->id, 'currency' => $o->currency, 'amount_minor' => $amount]);
        });
    }

    /** @param array<string,mixed> $input */
    public function release(User $owner, string $allocationId, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $allocationId, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['reason']);
            $payload = ['allocation_id' => $allocationId, ...$input];
            if ($row = LedgerInput::cached('purification_releases', $owner, $key, $payload)) {
                return $row;
            }
            $a = LedgerInput::owned('purification_allocations', $owner, $allocationId);
            if (DB::table('purification_releases')->where('allocation_id', $a->id)->exists()) {
                LedgerInput::fail('allocation', 'This allocation already has a retained release. No cash was moved.');
            }

            return $this->insert('purification_releases', $owner, $key, $payload, ['allocation_id' => $a->id]);
        });
    }

    public function currentDistribution(stdClass $distribution): void
    {
        if ($distribution->kind !== 'dividend' || DB::table('investment_events')->where('owner_id', $distribution->owner_id)->where('reverses_id', $distribution->id)->exists()) {
            LedgerInput::fail('distribution_id', 'Choose a current recorded distribution. Reversed evidence remains in history and needs a sourced correction.');
        }
    }

    /** @return array{account_id:int,currency:string,amount_minor:numeric-string} */
    public function eligibleJournal(User $owner, string $id): array
    {
        $j = LedgerInput::owned('journal_entries', $owner, $id);
        $legs = DB::table('postings as p')->join('accounts as a', 'a.id', '=', 'p.account_id')->where('p.journal_id', $j->id)
            ->select(['p.owner_id', 'p.account_id', 'p.currency_code', 'p.amount_minor', 'a.system_role', 'a.owner_id as account_owner'])->get();
        $cash = $legs->whereNull('system_role')->first();
        $expense = $legs->where('system_role', 'expense')->first();
        if ($j->kind !== 'expense' || $j->status !== 'posted' || $j->reverses_journal_id !== null || $legs->count() !== 2 || $cash === null || $expense === null
            || $legs->contains(fn ($leg): bool => (string) $leg->owner_id !== (string) $owner->id || (string) $leg->account_owner !== (string) $owner->id || $leg->currency_code !== $cash->currency_code)
            || bccomp(PortfolioInput::integer($cash->amount_minor), '0', 0) >= 0 || bccomp(bcadd(PortfolioInput::integer($cash->amount_minor), PortfolioInput::integer($expense->amount_minor), 0), '0', 0) !== 0
            || DB::table('investment_event_journals')->where('journal_id', $j->id)->exists() || DB::table('fx_operation_journals')->where('journal_id', $j->id)->exists()) {
            LedgerInput::fail('journal_id', 'Choose an actual posted personal expense in one currency. Reversals, transfers, investment fees and FX members cannot be attributed as charity payments.');
        }

        return ['account_id' => $cash->account_id, 'currency' => $cash->currency_code, 'amount_minor' => bcsub('0', PortfolioInput::integer($cash->amount_minor), 0)];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $data
     */
    private function insert(string $table, User $owner, string $key, array $input, array $data): stdClass
    {
        $id = DB::table($table)->insertGetId([...$data, 'owner_id' => $owner->id, 'actor_id' => $owner->id, 'actor_is_admin' => User::query()->findOrFail($owner->id)->is_admin,
            'reference' => (string) Str::ulid(), 'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($input),
            'reason' => LedgerInput::text($input['reason'] ?? null, 'reason'), 'recorded_at' => now('UTC')->format('Y-m-d H:i:sP')]);

        return DB::table($table)->where('id', $id)->sole();
    }

    private function optional(mixed $value, string $field, int $max): ?string
    {
        if (! is_string($value) || mb_strlen($value) > $max) {
            LedgerInput::fail($field, 'Use text up to '.$max.' characters, or leave it blank when missing.');
        }

        return trim($value) === '' ? null : trim($value);
    }

    /** @return numeric-string */
    private function amount(Currency $currency, mixed $value, string $field): string
    {
        try {
            $money = Money::fromDecimal($currency, $value);
            if (bccomp($money->minor, '0', 0) < 0) {
                throw new InvalidArgumentException('Use a nonnegative native-currency amount.');
            }

            return $money->minor;
        } catch (InvalidArgumentException $error) {
            LedgerInput::fail($field, $error->getMessage());
        }
    }
}
