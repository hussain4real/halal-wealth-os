<?php

declare(strict_types=1);

namespace App\Domain\Portfolio;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\PostingService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final readonly class InvestmentService
{
    public function __construct(private PortfolioRead $read, private InvestmentReplay $replay, private PostingService $posting) {}

    /** @param array<string,mixed> $input */
    public function record(User $owner, string $id, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $id, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, ['kind', 'asset_id', 'effective_date', 'quantity', 'unit_price', 'amount', 'fee', 'tax', 'split_numerator', 'split_denominator',
                'subscription_id', 'action_reference', 'confirmed', 'source', 'reason', 'replaces_id']);
            $payload = ['portfolio_account_id' => $id, 'input' => $input];
            if ($cached = LedgerInput::cached('investment_events', $owner, $key, $payload)) {
                return $cached;
            }
            $account = $this->active($owner, $id);
            $data = $this->prepare($owner, $account, $input);
            $checkDates = [$data['effective_date']];
            $priorFrames = $this->replay->capture($owner, $id, now('UTC')->format('Y-m-d\TH:i:s\Z'), checkDates: $checkDates)['cash_frames'];
            $prior = ($input['replaces_id'] ?? '') === '' ? null : $this->member($owner, $id, LedgerInput::identifier($input['replaces_id']));
            if ($prior !== null) {
                if ($prior->kind !== $data['kind'] || $prior->kind === 'ipo_intent' || $prior->action_reference !== $data['action_reference']) {
                    LedgerInput::fail('replaces_id', 'A correction retains event kind and business reference. Applications with dependent facts require linked child corrections.');
                }
                $this->cancel($owner, $account, $prior, 'investment-correct:'.hash('sha256', $key), $data['reason']);
            } elseif (DB::table('investment_events')->where('owner_id', $owner->id)->where('portfolio_account_id', $id)->where('action_reference', $data['action_reference'])->whereNull('replaces_id')->where('kind', '<>', 'reversal')->exists()) {
                LedgerInput::fail('action_reference', 'This statement/action reference is already retained. Open its receipt to retry, reverse or correct it.');
            }
            $event = $this->insert($owner, $account, $key, $payload, [...$data, 'replaces_id' => $prior?->id], $prior?->sort_id);
            $this->cash($owner, $account, $event);
            $this->replay->capture($owner, $id, now('UTC')->format('Y-m-d\TH:i:s\Z'), true, $priorFrames, $checkDates);

            return $event;
        });
    }

    public function reverse(User $owner, string $id, string $eventId, string $key, string $reason): stdClass
    {
        return DB::transaction(function () use ($owner, $id, $eventId, $key, $reason): stdClass {
            LedgerInput::lock($owner);
            $payload = ['portfolio_account_id' => $id, 'reverse_id' => $eventId, 'reason' => $reason];
            if ($cached = LedgerInput::cached('investment_events', $owner, $key, $payload)) {
                return $cached;
            }
            $account = $this->active($owner, $id);
            $prior = $this->member($owner, $id, $eventId);
            $priorFrames = $this->replay->capture($owner, $id, now('UTC')->format('Y-m-d\TH:i:s\Z'))['cash_frames'];
            $event = $this->cancel($owner, $account, $prior, $key, LedgerInput::text($reason, 'reason'), $payload);
            $this->replay->capture($owner, $id, now('UTC')->format('Y-m-d\TH:i:s\Z'), true, $priorFrames);

            return $event;
        });
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function prepare(User $owner, stdClass $account, array $input): array
    {
        $kind = $input['kind'] ?? null;
        if (! in_array($kind, ['buy', 'sell', 'dividend', 'fee', 'split', 'ipo_intent', 'ipo_reserve', 'ipo_release', 'ipo_pay', 'ipo_allot', 'ipo_refund', 'ipo_cancel'], true)) {
            LedgerInput::fail('kind', 'Choose a supported recorded investment fact.');
        }
        $asset = LedgerInput::owned('portfolio_assets', $owner, LedgerInput::identifier($input['asset_id'] ?? null));
        if ($asset->currency !== $account->currency || ! in_array($asset->kind, ['equity', 'unitized_fund'], true)) {
            LedgerInput::fail('asset_id', 'Use an equity or unitized fund in this investment account native currency. Manual investments have no invented units.');
        }
        $currency = Currency::from($account->currency);
        $amount = PortfolioInput::money($currency, $input['amount'] ?? '0', 'amount');
        $fee = PortfolioInput::money($currency, $input['fee'] ?? '0', 'fee');
        $tax = PortfolioInput::money($currency, $input['tax'] ?? '0', 'tax');
        if (in_array($kind, ['split', 'ipo_cancel'], true) ? $amount !== '0' : $amount === '0') {
            LedgerInput::fail('amount', 'Use a positive recorded consideration/application amount, or zero for a cashless split/cancellation.');
        }
        if ((! in_array($kind, ['buy', 'sell', 'dividend'], true) && $fee !== '0') || ($kind !== 'dividend' && $tax !== '0')) {
            LedgerInput::fail('fee', 'Only buy/sell/dividend facts accept fees; recorded tax deductions belong to dividends.');
        }
        if (in_array($kind, ['sell', 'dividend'], true) && bccomp(bcadd($fee, $tax, 0), $amount, 0) > 0) {
            LedgerInput::fail('fee', 'Deductions cannot exceed recorded gross consideration.');
        }
        try {
            Money::fromMinor($currency, bcadd($amount, $fee, 0));
        } catch (InvalidArgumentException) {
            LedgerInput::fail('amount', 'Total consideration and fees exceed the native money range.');
        }
        $unitized = in_array($kind, ['buy', 'sell', 'ipo_allot'], true);
        foreach (['quantity', 'unit_price'] as $field) {
            if (! $unitized && ! in_array($input[$field] ?? null, [null, ''], true)) {
                LedgerInput::fail($field, 'This event does not record units or an execution price.');
            }
        }
        foreach (['split_numerator', 'split_denominator'] as $field) {
            if ($kind !== 'split' && ! in_array($input[$field] ?? null, [null, ''], true)) {
                LedgerInput::fail($field, 'A split ratio belongs only to a split event.');
            }
        }
        $child = str_starts_with($kind, 'ipo_') && $kind !== 'ipo_intent';
        $subscription = $child ? $this->member($owner, (string) $account->id, LedgerInput::identifier($input['subscription_id'] ?? null)) : null;
        if ($subscription !== null && ($subscription->kind !== 'ipo_intent' || $subscription->asset_family !== $asset->family_reference)) {
            LedgerInput::fail('subscription_id', 'Select an application for the same asset family and account.');
        }
        if (! $child && ! in_array($input['subscription_id'] ?? null, [null, ''], true)) {
            LedgerInput::fail('subscription_id', 'Only IPO lifecycle facts belong to an application.');
        }
        if ($kind === 'ipo_allot' && ($input['confirmed'] ?? null) !== '1') {
            LedgerInput::fail('confirmed', 'Confirm actual allotted units from retained evidence. Intended spend is never shares.');
        }

        return ['kind' => $kind, 'asset_id' => $asset->id, 'asset_family' => $asset->family_reference, 'asset_kind' => $asset->kind,
            'effective_date' => LedgerInput::date($input['effective_date'] ?? null), 'quantity' => $unitized ? PortfolioInput::decimal($input['quantity'] ?? null, 'quantity', true) : null,
            'unit_price' => ! $unitized || in_array($input['unit_price'] ?? null, ['', null], true) ? null : PortfolioInput::decimal($input['unit_price'], 'unit_price'),
            'amount_minor' => $amount, 'fee_minor' => $fee, 'tax_minor' => $tax, 'subscription_id' => $subscription?->id,
            'split_numerator' => $kind === 'split' ? PortfolioInput::decimal($input['split_numerator'] ?? null, 'split_numerator', true) : null,
            'split_denominator' => $kind === 'split' ? PortfolioInput::decimal($input['split_denominator'] ?? null, 'split_denominator', true) : null,
            'action_reference' => LedgerInput::text($input['action_reference'] ?? null, 'action_reference'), 'source' => LedgerInput::text($input['source'] ?? null, 'source'),
            'reason' => LedgerInput::text($input['reason'] ?? null, 'reason')];
    }

    /** @param array<string,mixed> $payload
     * @param array<string,mixed> $data */
    private function insert(User $owner, stdClass $account, string $key, array $payload, array $data, ?int $sort = null): stdClass
    {
        LedgerInput::key($key);
        $id = DB::selectOne("SELECT nextval(pg_get_serial_sequence('investment_events','id')) AS id")->id;
        DB::table('investment_events')->insert(['id' => $id, 'owner_id' => $owner->id, 'portfolio_account_id' => $account->id, 'currency' => $account->currency,
            'reference' => (string) Str::ulid(), 'sort_id' => $sort ?? $id, 'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($payload),
            'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR), ...$data]);

        return LedgerInput::owned('investment_events', $owner, (string) $id);
    }

    /** @param array<string,mixed>|null $payload */
    private function cancel(User $owner, stdClass $account, stdClass $prior, string $key, string $reason, ?array $payload = null): stdClass
    {
        if ($prior->kind === 'reversal' || DB::table('investment_events')->where('reverses_id', $prior->id)->exists()) {
            LedgerInput::fail('event', 'This event is already reversed or cannot itself be reversed. Open its retained history.');
        }
        $event = $this->insert($owner, $account, $key, $payload ?? ['internal_reverse_id' => (string) $prior->id, 'reason' => $reason], [
            'kind' => 'reversal', 'asset_id' => $prior->asset_id, 'asset_family' => $prior->asset_family, 'asset_kind' => $prior->asset_kind,
            'effective_date' => $prior->effective_date, 'amount_minor' => '0', 'fee_minor' => '0', 'tax_minor' => '0', 'reverses_id' => $prior->id,
            'action_reference' => $prior->action_reference, 'source' => $prior->source, 'reason' => $reason], $prior->sort_id);
        $link = DB::table('investment_event_journals')->where('owner_id', $owner->id)->where('event_id', $prior->id)->first();
        if ($link !== null) {
            $journal = $this->posting->reverseInvestmentMember($owner, (string) $link->journal_id, 'investment-cash:'.hash('sha256', $key), $reason, (int) $event->id);
            $this->link($owner, $account, $event, $journal->id);
        }

        return $event;
    }

    private function cash(User $owner, stdClass $account, stdClass $event): void
    {
        if (! in_array($event->kind, ['buy', 'sell', 'dividend', 'fee', 'ipo_pay', 'ipo_refund'], true)) {
            return;
        }
        $currency = Currency::from($account->currency);
        $cash = InvestmentReplay::cash($event);
        $legs = [['account_id' => (string) $account->account_id, 'minor' => $cash]];
        $offset = $this->posting->systemAccountFor($owner, $currency, $event->kind === 'dividend' ? 'income' : ($event->kind === 'fee' ? 'expense' : 'clearing'));
        $offsetMinor = bcsub('0', $cash, 0);
        if ($event->kind === 'dividend' || $event->kind === 'sell') {
            $offsetMinor = bcsub('0', PortfolioInput::integer($event->amount_minor), 0);
            $deductions = bcadd(PortfolioInput::integer($event->fee_minor), PortfolioInput::integer($event->tax_minor), 0);
            if ($deductions !== '0') {
                $expense = $this->posting->systemAccountFor($owner, $currency, 'expense');
                $legs[] = ['account_id' => (string) $expense->id, 'minor' => $deductions];
            }
        }
        $legs[] = ['account_id' => (string) $offset->id, 'minor' => $offsetMinor];
        $journal = $this->posting->postBalanced($owner, 'investment-cash:'.hash('sha256', $event->idempotency_key), $legs,
            'Investment '.$event->kind.' · '.$event->reference, $event->effective_date, ['investment_event_id' => (string) $event->id],
            $event->kind === 'dividend' ? 'income' : ($event->kind === 'fee' ? 'expense' : 'adjustment'));
        $this->link($owner, $account, $event, $journal->id);
    }

    private function link(User $owner, stdClass $account, stdClass $event, int $journal): void
    {
        DB::table('investment_event_journals')->insert(['event_id' => $event->id, 'owner_id' => $owner->id, 'portfolio_account_id' => $account->id, 'currency' => $account->currency, 'journal_id' => $journal]);
    }

    private function active(User $owner, string $id): stdClass
    {
        $account = $this->read->account($owner, $id, true);
        if ($account->archived) {
            LedgerInput::fail('account_id', 'Archived investment accounts retain history but cannot receive new investment facts.');
        }

        return $account;
    }

    private function member(User $owner, string $id, string $eventId): stdClass
    {
        $row = LedgerInput::owned('investment_events', $owner, $eventId);
        abort_unless((string) $row->portfolio_account_id === $id, 404);

        return $row;
    }
}
