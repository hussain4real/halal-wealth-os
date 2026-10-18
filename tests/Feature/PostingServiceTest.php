<?php

namespace Tests\Feature;

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\PostingService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\OpeningBalanceIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function postingAccount(User $owner, Currency $currency = Currency::QAR, AccountType $type = AccountType::Bank, ?string $opening = null): Account
{
    return app(CreateAccount::class)->create($owner, 'Synthetic '.$type->value, $type, $currency, $opening === null ? null : Money::fromMinor($currency, $opening));
}

/** @return array<string, string> */
function postingInput(Account $account, string $kind = 'income', string $amount = '73.25'): array
{
    return ['kind' => $kind, 'account_id' => (string) $account->id, 'amount' => $amount, 'effective_date' => now()->toDateString(), 'description' => 'Synthetic movement', 'receipt_reference' => 'SYN-42'];
}

function postedMinor(Account $account): ?string
{
    return app(AccountBalanceReader::class)->forAccount($account)->money?->minor;
}

test('income expense and transfer post exact balanced owned journals with separate activity classification', function () {
    $owner = User::factory()->create();
    $a = postingAccount($owner);
    $b = postingAccount($owner, type: AccountType::Cash);
    $service = app(PostingService::class);
    expect(postedMinor($a))->toBe('0');
    $income = $service->record($owner, 'income-0001', postingInput($a));
    $expense = $service->record($owner, 'expense-001', postingInput($a, 'expense', '3.10'));
    expect(postedMinor($a))->toBe('7015');
    $transfer = $service->record($owner, 'transfer-001', postingInput($a, 'transfer', '12.34') + ['destination_account_id' => (string) $b->id]);
    expect(postedMinor($a))->toBe('5781')->and(postedMinor($b))->toBe('1234');
    expect(DB::table('postings')->selectRaw('journal_id, sum(amount_minor::numeric) as total')->groupBy('journal_id')->get()->pluck('total')->all())->toBe(['0', '0', '0']);
    expect(DB::table('journal_entries')->whereIn('kind', ['income', 'expense'])->count())->toBe(2);
    expect($transfer->postings->every(fn ($leg): bool => $leg->account->type->contributesToNetWorth()))->toBeTrue();
    expect($income->status)->toBe('posted')->and($expense->receipt_reference)->toBe('SYN-42');
    expect(bcadd(postedMinor($a), postedMinor($b), 0))->toBe('7015');
});

test('one minor unit imbalance and mixed currencies persist no journal or postings', function () {
    $owner = User::factory()->create();
    $a = postingAccount($owner);
    $b = postingAccount($owner);
    $other = postingAccount($owner, Currency::NGN);
    foreach ([[['account_id' => (string) $a->id, 'minor' => '10'], ['account_id' => (string) $b->id, 'minor' => '-9']], [['account_id' => (string) $a->id, 'minor' => '10'], ['account_id' => (string) $other->id, 'minor' => '-10']], []] as $legs) {
        expect(fn () => app(PostingService::class)->postBalanced($owner, 'imbalanced-001', $legs, 'Synthetic imbalance'))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('postings', 0);
    $this->assertDatabaseCount('journal_entries', 0);
});

test('exact JSON amount boundaries and currency exponents survive posting and receipt serialization', function (Currency $currency, string $amount, string $minor) {
    $owner = User::factory()->create();
    $account = postingAccount($owner, $currency);
    $response = $this->actingAs($owner)->postJson('/transactions', postingInput($account, amount: $amount) + ['idempotency_key' => 'exact-000001'])->assertCreated();
    expect(postedMinor($account))->toBe($minor);
    $this->get('/transactions/'.$response->json('id'))->assertInertia(fn (Assert $page) => $page->component('transactions/Show')->where('transaction.primary.minor', $minor)->where('transaction.primary.amount', $amount));
})->with([[Currency::QAR, '0.10', '10'], [Currency::JPY, '9007199254740993', '9007199254740993'], [Currency::KWD, '0.001', '1'], [Currency::QAR, '92233720368547758.07', Money::MAX_MINOR]]);

test('invalid movement inputs fail without monetary persistence', function (mixed $amount) {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $input = postingInput($account);
    $input['amount'] = $amount;
    $this->actingAs($owner)->postJson('/transactions', $input + ['idempotency_key' => 'invalid-0001'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('postings', 0);
})->with([0.1, 1, true, [['0.10']], '0', '-1.00', '0.001', '1.000', '92233720368547758.08', '1e3', '1,000.00']);

test('same payload retry returns one stable result and normalized decimal while changed details conflict', function () {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $service = app(PostingService::class);
    $input = postingInput($account, amount: '1');
    $a = $service->record($owner, 'retry-000001', $input);
    $input['amount'] = '1.00';
    $input['description'] = '  Synthetic movement  ';
    $b = $service->record($owner, 'retry-000001', $input);
    expect($a->id)->toBe($b->id);
    foreach (['amount' => '2.00', 'description' => 'Changed', 'receipt_reference' => 'Changed', 'effective_date' => '2026-01-01', 'kind' => 'expense'] as $field => $value) {
        $changed = $input;
        $changed[$field] = $value;
        expect(fn () => $service->record($owner, 'retry-000001', $changed))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('journal_entries', 1);
    expect(postedMinor($account))->toBe('100');
});

test('owner scope is enforced before retry lookup and owner namespaces are independent', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $b->forceFill(['is_admin' => true])->save();
    $aa = postingAccount($a);
    $bb = postingAccount($b);
    $service = app(PostingService::class);
    $original = $service->record($a, 'shared-key-0001', postingInput($aa));
    expect(fn () => $service->record($b, 'shared-key-0001', postingInput($aa)))->toThrow(ModelNotFoundException::class);
    $other = $service->record($b, 'shared-key-0001', postingInput($bb));
    expect($other->id)->not->toBe($original->id);
    $this->actingAs($b)->get('/transactions/'.$original->id)->assertNotFound();
    $this->get('/transactions/'.$original->id.'/correct')->assertNotFound();
    $this->postJson('/transactions/'.$original->id.'/reverse', ['idempotency_key' => 'reverse-other-001', 'reason' => 'Not mine'])->assertNotFound();
    $this->postJson('/transactions/'.$original->id.'/correct', postingInput($bb) + ['idempotency_key' => 'correct-other-001'])->assertNotFound();
    $this->get('/transactions')->assertInertia(fn (Assert $page) => $page->has('transactions', 1)->where('transactions.0.id', (string) $other->id));
});

test('submitted authority unknown fields and direct monetary edits cannot bypass the server', function (string $field) {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $this->actingAs($owner)->postJson('/transactions', postingInput($account) + ['idempotency_key' => 'authority-001', $field => 'spoofed'])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('journal_entries', 0);
})->with(['owner_id', 'status', 'postings', 'reverses_journal_id', 'payload_hash']);

test('all legs and key roll back for an injected failure at each financial write checkpoint', function (string $checkpoint) {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $enabled = true;
    $legs = 0;
    DB::listen(function (QueryExecuted $query) use ($checkpoint, &$enabled, &$legs): void {
        if (! $enabled) {
            return;
        }
        if (str_starts_with($query->sql, 'insert into "postings"')) {
            $legs++;
        }
        $hit = ($checkpoint === 'journal' && str_starts_with($query->sql, 'insert into "journal_entries"'))
            || ($checkpoint === 'leg' && $legs === 1)
            || ($checkpoint === 'status' && str_starts_with($query->sql, 'update "journal_entries"'));
        if ($hit) {
            $enabled = false;
            throw new \RuntimeException('Injected financial failure');
        }
    });
    expect(fn () => app(PostingService::class)->record($owner, 'failed-retry-001', postingInput($account)))->toThrow(\RuntimeException::class);
    $enabled = false;
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('postings', 0);
    expect(postedMinor($account))->toBe('0');
    $success = app(PostingService::class)->record($owner, 'failed-retry-001', postingInput($account));
    expect($success->status)->toBe('posted');
    $this->assertDatabaseCount('journal_entries', 1);
})->with(['journal', 'leg', 'status']);

test('opening is consumed explicitly once preserving the intent and original plus reversal restore zero', function () {
    $owner = User::factory()->create();
    $account = postingAccount($owner, opening: '-8125');
    $service = app(PostingService::class);
    expect(postedMinor($account))->toBeNull();
    $this->assertDatabaseCount('journal_entries', 0);
    $intent = OpeningBalanceIntent::query()->sole();
    $originalBytes = $intent->getAttributes();
    $opening = $service->postOpening($owner, (string) $account->id, 'opening-0001');
    expect(postedMinor($account))->toBe('-8125')->and($intent->fresh()->getAttributes())->toBe($originalBytes);
    expect($service->postOpening($owner, (string) $account->id, 'opening-0001')->id)->toBe($opening->id);
    expect(fn () => $service->postOpening($owner, (string) $account->id, 'opening-0002'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('opening_balance_postings', 1);
    $reversal = $service->reverse($owner, (string) $opening->id, 'reversal-0001', 'Synthetic correction');
    expect($opening->fresh()->status)->toBe('reversed')->and($reversal->reverses_journal_id)->toBe($opening->id)->and(postedMinor($account))->toBe('0');
    $this->assertDatabaseCount('postings', 4);
    expect(fn () => $service->postOpening($owner, (string) $account->id, 'opening-0003'))->toThrow(ValidationException::class);
    $this->actingAs($owner)->get('/accounts/'.$account->id)->assertInertia(fn (Assert $page) => $page->where('account.opening_request.status', 'reversed')->where('account.opening_request.journal_id', (string) $opening->id)->where('account.balance.minor', '0'));
});

test('a consumed opening rejects an unused key rather than returning an unbound successful identity', function () {
    $owner = User::factory()->create();
    $account = postingAccount($owner, opening: '100');
    $service = app(PostingService::class);
    $service->postOpening($owner, (string) $account->id, 'opening-bound-001');
    expect(fn () => $service->postOpening($owner, (string) $account->id, 'opening-unbound-1'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('journal_entries', 1);
});

test('year zero is rejected as a field error before reaching PostgreSQL', function () {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $input = postingInput($account);
    $input['effective_date'] = '0000-01-01';
    $this->actingAs($owner)->postJson('/transactions', $input + ['idempotency_key' => 'year-zero-0001'])->assertUnprocessable()->assertJsonValidationErrors('effective_date');
    $this->assertDatabaseCount('journal_entries', 0);
});

test('full signed limits including zero openings post and reverse without narrowing Money or truncating aggregates', function (string $minor) {
    $owner = User::factory()->create();
    $account = postingAccount($owner, opening: $minor);
    $service = app(PostingService::class);
    $opening = $service->postOpening($owner, (string) $account->id, 'boundary-opening');
    expect(postedMinor($account))->toBe($minor);
    if ($minor === Money::MIN_MINOR) {
        expect($opening->postings->count())->toBe(3);
        $equity = Account::query()->where('system_role', 'equity')->sole();
        expect(app(AccountBalanceReader::class)->forAccount($equity)->unavailableReason)->toBe('out_of_range');
    }
    $service->reverse($owner, (string) $opening->id, 'boundary-reversal', 'Boundary reversal');
    expect(postedMinor($account))->toBe('0');
    expect(DB::table('postings')->where('amount_minor', '>', Money::MAX_MINOR)->count())->toBe(0);
})->with([Money::MIN_MINOR, Money::MAX_MINOR, '0']);

test('new derived total overflow is explicit and complete cancellation recovers independently of posting order', function () {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $service = app(PostingService::class);
    $large = $service->record($owner, 'large-000001', postingInput($account, amount: '92233720368547758.07'));
    $service->record($owner, 'large-000002', postingInput($account, amount: '0.01'));
    expect(app(AccountBalanceReader::class)->forAccount($account)->unavailableReason)->toBe('out_of_range');
    $service->reverse($owner, (string) $large->id, 'large-reversal', 'Cancel original');
    expect(postedMinor($account))->toBe('1');
});

test('correction reverses and replaces atomically and a failed replacement preserves the original', function () {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $service = app(PostingService::class);
    $original = $service->record($owner, 'correct-original', postingInput($account));
    $enabled = true;
    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$enabled, &$inserts): void {
        if ($enabled && str_starts_with($query->sql, 'insert into "journal_entries"') && ++$inserts === 2) {
            $enabled = false;
            throw new \RuntimeException('Replacement failure');
        }
    });
    expect(fn () => $service->correct($owner, (string) $original->id, 'correct-request', postingInput($account, amount: '9.10')))->toThrow(\RuntimeException::class);
    $enabled = false;
    expect($original->fresh()->status)->toBe('posted')->and(postedMinor($account))->toBe('7325');
    $this->assertDatabaseCount('journal_entries', 1);
    $replacement = $service->correct($owner, (string) $original->id, 'correct-request', postingInput($account, amount: '9.10'));
    expect(postedMinor($account))->toBe('910')->and($replacement->replaces_journal_id)->toBe($original->id)->and($original->fresh()->status)->toBe('reversed');
    expect($service->correct($owner, (string) $original->id, 'correct-request', postingInput($account, amount: '9.10'))->id)->toBe($replacement->id);
    $this->assertDatabaseCount('journal_entries', 3);
});

test('archived accounts allow stable authorized replay and necessary reversal but refuse new movements', function () {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $service = app(PostingService::class);
    $input = postingInput($account);
    $original = $service->record($owner, 'archive-original', $input);
    $account->archived_at = now();
    $account->save();
    expect($service->record($owner, 'archive-original', $input)->id)->toBe($original->id);
    expect(fn () => $service->record($owner, 'archive-new-0001', $input))->toThrow(ValidationException::class);
    expect(fn () => $service->correct($owner, (string) $original->id, 'archive-correct', $input))->toThrow(ValidationException::class);
    expect($original->fresh()->status)->toBe('posted');
    $reversal = $service->reverse($owner, (string) $original->id, 'archive-reverse', 'Correct archived history');
    expect(postedMinor($account))->toBe('0')->and($service->reverse($owner, (string) $original->id, 'archive-reverse', 'Correct archived history')->id)->toBe($reversal->id);
    expect(fn () => $service->reverse($owner, (string) $original->id, 'archive-reverse2', 'Again'))->toThrow(ValidationException::class);
    expect(fn () => $service->reverse($owner, (string) $reversal->id, 'reverse-reversal', 'Again'))->toThrow(ValidationException::class);
});

test('invalid transfer source destination date and service authority never persist', function (string $case) {
    $owner = User::factory()->create();
    $account = postingAccount($owner);
    $input = postingInput($account, 'transfer');
    $input['destination_account_id'] = (string) $account->id;
    if ($case === 'currency') {
        $input['destination_account_id'] = (string) postingAccount($owner, Currency::NGN)->id;
    }
    if ($case === 'internal') {
        $input['destination_account_id'] = (string) postingAccount($owner, type: AccountType::InternalClearing)->id;
    }
    if ($case === 'date') {
        $input = postingInput($account);
        $input['effective_date'] = '2026-02-31';
    }
    if ($case === 'future') {
        $input = postingInput($account);
        $input['effective_date'] = now()->addDay()->toDateString();
    }
    if ($case === 'authority') {
        $input = postingInput($account);
        $input['owner_id'] = '1';
    }
    expect(fn () => app(PostingService::class)->record($owner, 'invalid-command', $input))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('journal_entries', 0);
})->with(['self', 'currency', 'internal', 'date', 'future', 'authority']);

test('posted financial values receipts legs links and owner history resist direct SQL edits', function () {
    $owner = User::factory()->create();
    $account = postingAccount($owner, opening: '100');
    $service = app(PostingService::class);
    $journal = $service->postOpening($owner, (string) $account->id, 'immutable-open');
    foreach ([fn () => DB::table('journal_entries')->where('id', $journal->id)->update(['description' => 'Edited']), fn () => DB::table('journal_entries')->where('id', $journal->id)->delete(), fn () => DB::table('postings')->where('journal_id', $journal->id)->update(['amount_minor' => '0']), fn () => DB::table('postings')->where('journal_id', $journal->id)->delete(), fn () => DB::table('opening_balance_intents')->update(['amount_minor' => '0']), fn () => DB::table('opening_balance_postings')->delete()] as $mutation) {
        expect(fn () => DB::transaction($mutation))->toThrow(QueryException::class);
    }
    expect(postedMinor($account))->toBe('100');
    $this->actingAs($owner)->patchJson('/transactions/'.$journal->id, ['amount' => '0'])->assertMethodNotAllowed();
    $this->delete('/transactions/'.$journal->id)->assertMethodNotAllowed();
});

test('transaction middleware preserves private authentication verification and production two factor', function () {
    $this->get('/transactions')->assertRedirect(route('login'));
    $this->post('/transactions')->assertRedirect(route('login'));
    $owner = User::factory()->unverified()->create();
    $this->actingAs($owner)->get('/transactions/new')->assertRedirect(route('verification.notice'));
    $owner->forceFill(['email_verified_at' => now()])->save();
    $this->app->instance('env', 'production');
    $this->get('/transactions')->assertRedirect(route('security.edit'));
    $this->withSession(['_token' => 'synthetic-csrf-token'])->post('/transactions', ['_token' => 'synthetic-csrf-token'])->assertRedirect(route('security.edit'));
    $owner->forceFill(['two_factor_secret' => encrypt('synthetic-secret'), 'two_factor_confirmed_at' => now()])->save();
    $this->get('/transactions')->assertOk();
    $this->assertDatabaseCount('journal_entries', 0);
});
