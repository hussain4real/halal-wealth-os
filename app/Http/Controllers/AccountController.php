<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Http\Requests\Accounts\ArchiveAccountRequest;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request, AccountBalanceReader $balances): Response
    {
        $accounts = Account::query()->ownedBy($this->owner($request))->with('openingBalanceIntent')->orderBy('id')->get();

        return Inertia::render('accounts/Index', [
            'accounts' => $accounts->map(fn (Account $account): array => $this->payload($account, $balances))->all(),
            'currencies' => array_map(fn (Currency $currency): array => ['code' => $currency->value, 'exponent' => $currency->exponent()], Currency::cases()),
            'types' => array_map(fn (AccountType $type): string => $type->value, AccountType::cases()),
        ]);
    }

    public function store(StoreAccountRequest $request, CreateAccount $creator): RedirectResponse
    {
        $currency = Currency::from($request->string('currency_code')->toString());
        $opening = $request->validated('opening_balance');
        $account = $creator->create($this->owner($request), $request->string('name')->toString(), AccountType::from($request->string('type')->toString()), $currency,
            $opening === null ? null : Money::fromDecimal($currency, $opening));

        return to_route('accounts.show', $account);
    }

    public function show(Request $request, string $account, AccountBalanceReader $balances): Response
    {
        $record = $this->owned($request, $account);
        Gate::authorize('view', $record);

        return Inertia::render('accounts/Show', ['account' => $this->payload($record->load('openingBalanceIntent'), $balances)]);
    }

    public function update(UpdateAccountRequest $request, string $account): RedirectResponse
    {
        $record = DB::transaction(function () use ($request, $account): Account {
            $record = $this->owned($request, $account, true);
            Gate::authorize('update', $record);
            $record->update(['name' => $request->string('name')->toString()]);

            return $record;
        });

        return to_route('accounts.show', $record);
    }

    public function archive(ArchiveAccountRequest $request, string $account): RedirectResponse
    {
        $record = DB::transaction(function () use ($request, $account): Account {
            $record = $this->owned($request, $account, true);
            Gate::authorize('update', $record);
            $record->archived_at = now();
            $record->save();

            return $record;
        });

        return to_route('accounts.show', $record);
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        if (! $owner instanceof User) {
            abort(403);
        }

        return $owner;
    }

    private function owned(Request $request, string $id, bool $lock = false): Account
    {
        $query = Account::query()->ownedBy($this->owner($request))->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(Account $account, AccountBalanceReader $balances): array
    {
        $snapshot = $balances->forAccount($account);
        $intent = $account->openingBalanceIntent;
        $openingJournal = $intent === null ? null : DB::table('opening_balance_postings')->join('journal_entries', 'journal_entries.id', '=', 'opening_balance_postings.journal_id')->where('opening_intent_id', $intent->id)->where('journal_entries.owner_id', $account->owner_id)->first(['journal_entries.id', 'journal_entries.status']);

        return [
            'id' => (string) $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'currency' => $account->currency_code->value,
            'archived' => $account->archived_at !== null,
            'system' => $account->system_role !== null,
            'included_in_net_worth' => $account->type->contributesToNetWorth(),
            'balance' => $snapshot->money?->jsonSerialize(),
            'balance_status' => $snapshot->money === null ? 'unavailable' : 'derived',
            'balance_reason' => $snapshot->unavailableReason,
            'net_worth_contribution' => $snapshot->netWorthContribution($account->type)?->jsonSerialize(),
            'opening_request' => $intent === null ? null : [
                'status' => $openingJournal->status ?? 'awaiting_posting',
                'journal_id' => $openingJournal === null ? null : (string) $openingJournal->id,
                'amount' => Money::fromMinor($intent->currency_code, $intent->amount_minor)->jsonSerialize(),
            ],
        ];
    }
}
