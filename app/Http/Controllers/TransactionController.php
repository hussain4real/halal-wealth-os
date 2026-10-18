<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\PostingService;
use App\Domain\Shared\Money\Currency;
use App\Http\Requests\Transactions\TransactionRequest;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $journals = JournalEntry::query()->where('owner_id', $this->owner($request)->id)->with('postings.account')->orderByDesc('id')->simplePaginate(20);

        return Inertia::render('transactions/Index', ['transactions' => $journals->getCollection()->map(fn (JournalEntry $journal): array => $this->payload($journal))->all(),
            'previous' => $journals->previousPageUrl(), 'next' => $journals->nextPageUrl(), 'has_accounts' => Account::query()->ownedBy($this->owner($request))->whereNull('archived_at')->whereNull('system_role')->whereIn('type', ['bank', 'cash', 'investment', 'liability'])->exists()]);
    }

    public function create(Request $request, AccountBalanceReader $balances): Response
    {
        return Inertia::render('transactions/Create', ['accounts' => $this->choices($request, $balances), 'today' => now()->toDateString(), 'original' => null]);
    }

    public function correction(Request $request, string $journal, AccountBalanceReader $balances): Response
    {
        $original = $this->owned($request, $journal)->load('postings.account');
        abort_unless($original->status === 'posted' && in_array($original->kind, ['income', 'expense', 'transfer'], true), 404);

        return Inertia::render('transactions/Create', ['accounts' => $this->choices($request, $balances), 'today' => now()->toDateString(), 'original' => $this->payload($original)]);
    }

    public function store(TransactionRequest $request, PostingService $posting): JsonResponse|RedirectResponse
    {
        $journal = $posting->record($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'));

        return $this->success($request, $journal);
    }

    public function correct(TransactionRequest $request, string $journal, PostingService $posting): JsonResponse|RedirectResponse
    {
        $record = $posting->correct($this->owner($request), $journal, $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'));

        return $this->success($request, $record);
    }

    public function reverse(TransactionRequest $request, string $journal, PostingService $posting): JsonResponse|RedirectResponse
    {
        $record = $posting->reverse($this->owner($request), $journal, $request->string('idempotency_key')->toString(), $request->string('reason')->toString());

        return $this->success($request, $record);
    }

    public function postOpening(TransactionRequest $request, string $account, PostingService $posting): JsonResponse|RedirectResponse
    {
        $record = $posting->postOpening($this->owner($request), $account, $request->string('idempotency_key')->toString());

        return $this->success($request, $record);
    }

    public function show(Request $request, string $journal): Response
    {
        $record = $this->owned($request, $journal)->load('postings.account');
        $related = JournalEntry::query()->where('owner_id', $this->owner($request)->id)->where(function ($query) use ($record): void {
            $query->where('reverses_journal_id', $record->id)->orWhere('replaces_journal_id', $record->id);
            if ($record->reverses_journal_id !== null) {
                $query->orWhere('id', $record->reverses_journal_id);
            }
            if ($record->replaces_journal_id !== null) {
                $query->orWhere('id', $record->replaces_journal_id);
            }
        })->with('postings.account')->orderBy('id')->get();

        return Inertia::render('transactions/Show', ['transaction' => $this->payload($record), 'related' => $related->map(fn (JournalEntry $journal): array => $this->payload($journal))->all()]);
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }

    private function owned(Request $request, string $id): JournalEntry
    {
        return JournalEntry::query()->where('owner_id', $this->owner($request)->id)->whereKey($id)->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function choices(Request $request, AccountBalanceReader $balances): array
    {
        return array_values(Account::query()->ownedBy($this->owner($request))->whereNull('system_role')->whereNull('archived_at')->orderBy('name')->get()
            ->filter(fn (Account $account): bool => $account->type->contributesToNetWorth())
            ->map(function (Account $account) use ($balances): array {
                $snapshot = $balances->forAccount($account);

                return ['id' => (string) $account->id, 'name' => $account->name, 'currency' => $account->currency_code->value, 'exponent' => $account->currency_code->exponent(),
                    'balance' => $snapshot->money?->amount(), 'balance_reason' => $snapshot->unavailableReason];
            })->values()->all());
    }

    private function success(Request $request, JournalEntry $journal): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => (string) $journal->id, 'reference' => $journal->reference, 'status' => $journal->status, 'url' => route('transactions.show', $journal, false)], 201);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transaction recorded. Your receipt is ready.']);

        return to_route('transactions.show', $journal);
    }

    /** @return array<string, mixed> */
    private function payload(JournalEntry $journal): array
    {
        $movements = [];
        foreach ($journal->postings as $posting) {
            $id = (string) $posting->account_id;
            $movements[$id] ??= ['account_id' => $id, 'name' => $posting->account->name, 'currency' => $posting->currency_code->value, 'minor' => '0', 'internal' => ! $posting->account->type->contributesToNetWorth()];
            $movements[$id]['minor'] = bcadd($movements[$id]['minor'], $posting->amount_minor, 0);
        }
        foreach ($movements as &$movement) {
            $exponent = Currency::from($movement['currency'])->exponent();
            $movement['amount'] = bcdiv($movement['minor'], bcpow('10', (string) $exponent, 0), $exponent);
        }
        unset($movement);
        $primary = collect($movements)->firstWhere('internal', false) ?? collect($movements)->first();

        return ['id' => (string) $journal->id, 'reference' => $journal->reference, 'kind' => $journal->kind, 'status' => $journal->status, 'description' => $journal->description,
            'effective_date' => $journal->effective_date->format('Y-m-d'), 'recorded_at' => $journal->created_at->toIso8601String(), 'receipt_reference' => $journal->receipt_reference,
            'reverses_id' => $journal->reverses_journal_id === null ? null : (string) $journal->reverses_journal_id, 'replaces_id' => $journal->replaces_journal_id === null ? null : (string) $journal->replaces_journal_id,
            'primary' => $primary, 'movements' => array_values($movements)];
    }
}
