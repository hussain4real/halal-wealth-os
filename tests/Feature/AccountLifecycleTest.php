<?php

namespace Tests\Feature;

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\BalanceSnapshot;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\OpeningBalanceGateway;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\OpeningBalanceIntent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function m1Account(User $owner, AccountType $type = AccountType::Bank, ?Money $opening = null): Account
{
    return app(CreateAccount::class)->create($owner, 'Synthetic account', $type, Currency::QAR, $opening);
}

test('account routes require authentication verified email and production two factor', function () {
    $this->get('/accounts')->assertRedirect(route('login'));
    $this->post('/accounts')->assertRedirect(route('login'));
    $owner = User::factory()->unverified()->create();
    $this->actingAs($owner)->get('/accounts')->assertRedirect(route('verification.notice'));
    $owner->forceFill(['email_verified_at' => now()])->save();
    $this->app->instance('env', 'production');
    $this->get('/accounts')->assertRedirect(route('security.edit'));
    $this->withSession(['_token' => 'synthetic-csrf-token'])->post('/accounts', ['name' => 'Blocked', 'type' => 'cash', 'currency_code' => 'QAR', '_token' => 'synthetic-csrf-token'])->assertRedirect(route('security.edit'));
    $owner->forceFill(['two_factor_secret' => encrypt('synthetic-secret'), 'two_factor_confirmed_at' => now()])->save();
    $this->get('/accounts')->assertOk();
    $this->assertDatabaseCount('accounts', 0);
});

test('each selected account type can be created but has no editable balance', function (AccountType $type) {
    $owner = User::factory()->create();
    $this->actingAs($owner)->post('/accounts', ['name' => 'Owned', 'type' => $type->value, 'currency_code' => 'QAR'])->assertSessionHasNoErrors()->assertRedirect();
    $account = Account::query()->sole();
    expect($account->owner_id)->toBe($owner->id)->and($account->type)->toBe($type);
    expect(Schema::hasColumn('accounts', 'balance'))->toBeFalse()->and(Schema::hasColumn('accounts', 'balance_minor'))->toBeFalse();
    $this->get('/accounts/'.$account->id)->assertInertia(fn (Assert $page) => $page->component('accounts/Show')->where('account.balance', null)->where('account.balance_status', 'unavailable')->where('account.included_in_net_worth', $type->contributesToNetWorth()));
})->with(AccountType::cases());

test('large opening input persists exact integer minor units and remains unposted through Inertia JSON', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->postJson('/accounts', ['name' => 'Large synthetic', 'type' => 'bank', 'currency_code' => 'QAR', 'opening_balance' => '90071992547409.93'])->assertRedirect();
    $intent = OpeningBalanceIntent::query()->sole();
    expect($intent->amount_minor)->toBe('9007199254740993');
    $account = Account::query()->sole();
    $this->get('/accounts/'.$account->id)->assertInertia(fn (Assert $page) => $page
        ->where('account.id', (string) $account->id)->where('account.balance', null)->where('account.net_worth_contribution', null)
        ->where('account.opening_request.status', 'awaiting_posting')->where('account.opening_request.amount.minor', '9007199254740993')
        ->where('account.opening_request.amount.amount', '90071992547409.93'));
    expect(Schema::hasTable('postings'))->toBeFalse()->and(Schema::hasTable('journal_entries'))->toBeFalse();
});

test('invalid cash precision range and JSON numbers are rejected before any persistence', function (mixed $amount) {
    $owner = User::factory()->create();
    $this->actingAs($owner)->postJson('/accounts', ['name' => 'Invalid', 'type' => 'cash', 'currency_code' => 'QAR', 'opening_balance' => $amount])->assertUnprocessable()->assertJsonValidationErrors('opening_balance');
    $this->assertDatabaseCount('accounts', 0);
    $this->assertDatabaseCount('opening_balance_intents', 0);
})->with([0.1, 1, true, [['0.10']], '0.001', '1.000', '92233720368547758.08', '-92233720368547758.09', '1e3', '1,000.00']);

test('both signed cash limits persist and serialize without integer loss', function (string $amount, string $minor) {
    $owner = User::factory()->create();
    $this->actingAs($owner)->postJson('/accounts', ['name' => 'Boundary', 'type' => 'bank', 'currency_code' => 'QAR', 'opening_balance' => $amount])->assertRedirect();
    $intent = OpeningBalanceIntent::query()->sole();
    expect($intent->amount_minor)->toBe($minor);
    $this->get('/accounts/'.$intent->account_id)->assertInertia(fn (Assert $page) => $page->where('account.opening_request.amount.minor', $minor)->where('account.opening_request.amount.amount', $amount)->where('account.balance', null));
})->with([['92233720368547758.07', Money::MAX_MINOR], ['-92233720368547758.08', Money::MIN_MINOR]]);

test('opening request failure rolls back metadata without pretending to test journal posting', function () {
    $owner = User::factory()->create();
    $this->app->bind(OpeningBalanceGateway::class, fn () => new class implements OpeningBalanceGateway
    {
        public function request(Account $account, Money $amount): OpeningBalanceIntent
        {
            throw new \RuntimeException('Synthetic metadata failure');
        }
    });
    expect(fn () => m1Account($owner, opening: Money::fromMinor(Currency::QAR, '100')))->toThrow(\RuntimeException::class);
    $this->assertDatabaseCount('accounts', 0);
    $this->assertDatabaseCount('opening_balance_intents', 0);
});

test('unsupported codes are rejected and currency exponents are enforced before persistence', function () {
    $owner = User::factory()->create();
    $base = ['name' => 'Synthetic', 'type' => 'bank'];
    $this->actingAs($owner)->postJson('/accounts', $base + ['currency_code' => 'XXX'])->assertJsonValidationErrors('currency_code');
    $this->postJson('/accounts', $base + ['currency_code' => 'qar'])->assertJsonValidationErrors('currency_code');
    $this->postJson('/accounts', $base + ['currency_code' => 'JPY', 'opening_balance' => '1.1'])->assertJsonValidationErrors('opening_balance');
    $this->assertDatabaseCount('accounts', 0);
    $this->postJson('/accounts', $base + ['currency_code' => 'KWD', 'opening_balance' => '0.001'])->assertRedirect();
    expect(OpeningBalanceIntent::query()->sole()->amount_minor)->toBe('1');
});

test('submitted owner balance capability status or unknown fields cannot mutate account authority', function (string $field, mixed $value) {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($owner)->postJson('/accounts', ['name' => 'Spoofed', 'type' => 'bank', 'currency_code' => 'QAR', $field => $value === 'other-owner' ? $other->id : $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('accounts', 0);
})->with([['owner_id', 'other-owner'], ['id', '1'], ['is_admin', true], ['balance', '100'], ['balance_minor', '100'], ['archived_at', '2026-01-01'], ['status', 'posted'], ['unknown', 'data']]);

test('all owner lookups and writes deny a second owner even with explicit admin capability', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $account = m1Account($ownerA, opening: Money::fromMinor(Currency::QAR, '500'));
    $ownerB->forceFill(['is_admin' => true])->save();
    $this->actingAs($ownerB)->get('/accounts')->assertInertia(fn (Assert $page) => $page->has('accounts', 0));
    $this->get('/accounts/'.$account->id)->assertNotFound();
    $this->patchJson('/accounts/'.$account->id, ['name' => 'Stolen'])->assertNotFound();
    $this->patchJson('/accounts/'.$account->id.'/archive')->assertNotFound();
    expect(Gate::forUser($ownerB)->allows('view', $account))->toBeFalse();
    expect($account->fresh()->name)->toBe('Synthetic account')->and($account->fresh()->archived_at)->toBeNull();
    expect(OpeningBalanceIntent::query()->sole()->amount_minor)->toBe('500');
});

test('type currency owner balance and opening request cannot be changed on rename', function (string $field, mixed $value) {
    $owner = User::factory()->create();
    $account = m1Account($owner);
    $this->actingAs($owner)->patchJson('/accounts/'.$account->id, ['name' => 'Changed', $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($account->fresh()->name)->toBe('Synthetic account');
})->with([['currency_code', 'NGN'], ['type', 'liability'], ['owner_id', '2'], ['balance', '30'], ['opening_balance', '30.00']]);

test('archive retains account and immutable request and refuses changes and deletion', function () {
    $owner = User::factory()->create();
    $account = m1Account($owner, opening: Money::fromMinor(Currency::QAR, '-7000'));
    $this->actingAs($owner)->patch('/accounts/'.$account->id, ['name' => 'Renamed'])->assertSessionHasNoErrors();
    $this->patch('/accounts/'.$account->id.'/archive')->assertRedirect();
    $this->get('/accounts/'.$account->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('account.archived', true)->where('account.opening_request.amount.minor', '-7000'));
    $this->patchJson('/accounts/'.$account->id, ['name' => 'Forbidden'])->assertForbidden();
    $this->delete('/accounts/'.$account->id)->assertMethodNotAllowed();
    $this->assertDatabaseCount('accounts', 1);
    $this->assertDatabaseCount('opening_balance_intents', 1);
    expect(fn () => $account->fresh()->delete())->toThrow(LogicException::class);
    expect(fn () => $account->fresh()->update(['name' => 'Bypassed']))->toThrow(LogicException::class);
    expect(fn () => app(OpeningBalanceGateway::class)->request($account->fresh(), Money::fromMinor(Currency::QAR, '1')))->toThrow(\InvalidArgumentException::class);
    $intent = OpeningBalanceIntent::query()->sole();
    expect(fn () => $intent->update(['amount_minor' => '0']))->toThrow(LogicException::class);
});

test('owner deletion cannot cascade away active or archived financial accounts', function () {
    $owner = User::factory()->create();
    $account = m1Account($owner);
    $account->archived_at = now();
    $account->save();
    $this->actingAs($owner)->deleteJson(route('profile.destroy'), ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->assertAuthenticatedAs($owner);
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $owner->id)->delete()))->toThrow(QueryException::class);
    $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    $this->assertDatabaseHas('users', ['id' => $owner->id]);
});

test('a stale active model cannot rename an account after it is archived', function () {
    $owner = User::factory()->create();
    $account = m1Account($owner);
    $stale = Account::query()->findOrFail($account->id);
    $account->archived_at = now();
    $account->save();
    expect(fn () => $stale->update(['name' => 'After archive']))->toThrow(LogicException::class);
    expect($account->fresh()->name)->toBe('Synthetic account');
});

test('a stale active model cannot submit an opening request after archiving', function () {
    $owner = User::factory()->create();
    $account = m1Account($owner);
    $stale = Account::query()->findOrFail($account->id);
    $account->archived_at = now();
    $account->save();
    expect(fn () => app(OpeningBalanceGateway::class)->request($stale, Money::fromMinor(Currency::QAR, '1')))->toThrow(\InvalidArgumentException::class);
    $this->assertDatabaseCount('opening_balance_intents', 0);
});

test('derived adapter values remain strings with liability credits and clearing exclusion in response', function () {
    $owner = User::factory()->create();
    $liability = m1Account($owner, AccountType::Liability);
    $clearing = m1Account($owner, AccountType::InternalClearing);
    $this->app->bind(AccountBalanceReader::class, fn () => new class implements AccountBalanceReader
    {
        public function forAccount(Account $account): BalanceSnapshot
        {
            return BalanceSnapshot::derive($account->currency_code, [Money::fromMinor($account->currency_code, '-10000'), Money::fromMinor($account->currency_code, '11000')]);
        }
    });
    $this->actingAs($owner)->get('/accounts/'.$liability->id)->assertInertia(fn (Assert $page) => $page->where('account.balance.amount', '10.00')->where('account.net_worth_contribution.minor', '1000')->where('account.balance_status', 'derived'));
    $this->get('/accounts/'.$clearing->id)->assertInertia(fn (Assert $page) => $page->where('account.balance.minor', '1000')->where('account.included_in_net_worth', false)->where('account.net_worth_contribution', null));
});
