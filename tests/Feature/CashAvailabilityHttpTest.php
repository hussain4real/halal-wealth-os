<?php

use App\Domain\Planning\CashAvailability;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AvailabilityFixtures as A;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->account = Fx::account($this->owner);
    G::cash($this->owner, $this->account, '1000');
});
function availabilityFinancialBytes(): array
{
    $result = [];
    foreach (['accounts', 'opening_balance_intents', 'journal_entries', 'postings', 'planning_categories', 'planning_schedules', 'planning_occurrences', 'planning_attributions', 'planning_allocations', 'planning_releases', 'planning_requests', 'goal_plans', 'goal_reservations', 'goal_releases', 'goal_funding_links', 'goal_requests', 'fx_observations', 'valuation_reports', 'cash_availability_settings'] as $table) {
        $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $result;
}
test('dashboard uses real incomplete and complete owner cash instead of a static empty amount', function () {
    $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('cash.actual.state', 'incomplete')->where('cash.actual.deployable', null)->where('cash.account_count', 1));
    A::settings($this->owner);
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('cash.actual.state', 'complete')->where('cash.actual.deployable.minor', '95000'));
});
test('scenario fields and repeat requests change only forecast and preserve authoritative database bytes', function (string $field, string $value) {
    G::goal($this->owner, $this->account, ['opening' => '100', 'contribution' => '10']);
    B::rule($this->owner, B::category($this->owner, ['direction' => 'income']), ['salary' => '1', 'amount' => '200', 'first_due_date' => '2026-10-28']);
    A::settings($this->owner);
    $snapshot = app(CashAvailability::class)->capture($this->owner);
    $before = availabilityFinancialBytes();
    $payload = ['fingerprint' => $snapshot['fingerprint'], 'overrides' => [$field => $value]];
    $this->actingAs($this->owner)->postJson('/cash/scenarios', $payload)->assertOk()->assertJsonPath('actual.raw.minor', $snapshot['actual']['raw']['minor'])->assertJsonPath('forecast.scenario', true);
    $this->postJson('/cash/scenarios', $payload)->assertOk();
    expect(availabilityFinancialBytes())->toBe($before);
})->with([['salary', '500'], ['contribution', '20'], ['investment_target', '100'], ['commitments', '40']]);
test('scenario source change returns conflict and old owner fingerprint cannot access another owner state', function () {
    A::settings($this->owner);
    $s = app(CashAvailability::class)->capture($this->owner);
    G::cash($this->owner, $this->account, '1');
    $this->actingAs($this->owner)->postJson('/cash/scenarios', ['fingerprint' => $s['fingerprint'], 'overrides' => ['salary' => '0']])->assertStatus(409);
    $other = User::factory()->create();
    A::settings($other);
    $this->actingAs($other)->postJson('/cash/scenarios', ['fingerprint' => $s['fingerprint'], 'overrides' => ['salary' => '0']])->assertStatus(409);
});
test('server rejects authority injection and invalid scenario amounts without writes', function () {
    A::settings($this->owner);
    $s = app(CashAvailability::class)->capture($this->owner);
    $before = availabilityFinancialBytes();
    $this->actingAs($this->owner)->postJson('/cash/scenarios', ['fingerprint' => $s['fingerprint'], 'overrides' => ['salary' => '1'], 'owner_id' => '2'])->assertUnprocessable();
    foreach (['-1', '1.001', '1e4', '92233720368547758.08'] as $value) {
        $this->postJson('/cash/scenarios', ['fingerprint' => $s['fingerprint'], 'overrides' => ['salary' => $value]])->assertUnprocessable();
    }
    expect(availabilityFinancialBytes())->toBe($before);
});
test('settings request retains old-prior uncertain retry after commit and rejects stale new operation', function () {
    $payload = ['idempotency_key' => 'cash-http-stable-request', ...A::input()];
    $first = $this->actingAs($this->owner)->postJson('/cash/settings', $payload)->assertCreated();
    $this->postJson('/cash/settings', $payload)->assertCreated()->assertJsonPath('id', $first->json('id'));
    $this->postJson('/cash/settings', [...$payload, 'idempotency_key' => 'cash-http-new-stale-request'])->assertUnprocessable()->assertJsonValidationErrors('prior_id');
    $this->assertDatabaseCount('cash_availability_settings', 1);
    $this->get('/cash/settings')->assertOk()->assertInertia(fn (Assert $p) => $p->component('cash/Settings')->where('cash.settings.id', $first->json('id')));
});
test('anonymous unverified and foreign settings references cannot reach privileged planning writes', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->postJson('/cash/settings', ['idempotency_key' => 'cash-unverified', ...A::input()])->assertForbidden();
    $foreign = User::factory()->create();
    $bill = B::rule($foreign, B::category($foreign));
    $this->actingAs($this->owner)->postJson('/cash/settings', ['idempotency_key' => 'cash-foreign-schedule', ...A::input(['requirements' => [['schedule_id' => (string) $bill->id, 'required' => '1']]])])->assertNotFound();
    $this->assertDatabaseCount('cash_availability_settings', 0);
});

test('settings-only profile deletion retains identity session and planning history before logout', function () {
    $owner = User::factory()->create();
    A::settings($owner);
    expect(DB::table('accounts')->where('owner_id', $owner->id)->exists())->toBeFalse()
        ->and(DB::table('planning_categories')->where('owner_id', $owner->id)->exists())->toBeFalse();
    $this->actingAs($owner)->from('/settings/profile')->delete('/settings/profile', ['password' => 'password'])
        ->assertRedirect('/settings/profile')->assertSessionHasErrors('password');
    $this->assertAuthenticatedAs($owner);
    $this->assertDatabaseHas('users', ['id' => $owner->id]);
    $this->assertDatabaseHas('cash_availability_settings', ['owner_id' => $owner->id]);
});
