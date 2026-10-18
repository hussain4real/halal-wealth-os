<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Reporting\NetWorthSnapshot;
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
        $accounts = Account::query()->ownedBy($this->owner($request))->orderBy('id')->get();

        return Inertia::render('accounts/Index', [
            'accounts' => $accounts->map(fn (Account $account): array => $this->payload($account, $balances, $this->owner($request)))->all(),
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

        return Inertia::render('accounts/Show', ['account' => $this->payload($record, $balances, $this->owner($request))]);
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
    private function payload(Account $account, AccountBalanceReader $balances, User $owner): array
    {
        $snapshot = $balances->forAccount($account);
        $investment = $account->type === AccountType::Investment
            ? app(NetWorthSnapshot::class)->capture($owner, $account->currency_code, now('UTC')->format('Y-m-d\TH:i:s\Z'), (string) $account->id)['components'][0]
            : null;

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
            'net_worth_contribution' => $investment === null ? $snapshot->netWorthContribution($account->type)?->jsonSerialize() : $investment['native'],
            'valuation_mode' => $investment === null ? 'ledger' : (isset($investment['portfolio']) ? ($investment['portfolio']['mode'] ?? 'unconfirmed') : 'legacy_ledger'),
            'portfolio_id' => $investment['portfolio']['id'] ?? null,
            'net_worth_reason' => $investment['native_reason'] ?? null,
            'opening_request' => $snapshot->openingRequest?->jsonSerialize(),
        ];
    }
}
