<?php

use App\Domain\Ledger\PostingService;
use App\Domain\Planning\BudgetService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\FxFixtures as Fx;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->category = B::category($this->owner);
    $this->rule = B::rule($this->owner, $this->category);
    $this->account = Fx::account($this->owner);
    $this->journal = app(PostingService::class)->record($this->owner, 'budget-http-posted', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '147.25', 'effective_date' => '2026-10-18', 'description' => 'Synthetic budget receipt']);
    $this->allocation = ['schedule_id' => (string) $this->rule->id, 'due_date' => '2026-10-18', 'journal_id' => (string) $this->journal->id, 'amount' => '147.25', 'idempotency_key' => 'budget-http-match'];
});

test('budget routes enforce authentication verification and production two factor', function () {
    $this->get('/budgets')->assertRedirect('/login');
    $this->post('/budgets/categories', [])->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get('/budgets')->assertRedirect('/email/verify');
    $this->app->instance('env', 'production');
    $this->actingAs($this->owner)->get('/budgets')->assertRedirect(route('security.edit'));
    $this->withSession(['_token' => 'synthetic-csrf-token'])->withHeader('X-CSRF-TOKEN', 'synthetic-csrf-token')->postJson('/budgets/allocations', $this->allocation)->assertRedirect(route('security.edit'));
});

test('budget pages render owned exact string forecasts and bounded month navigation', function () {
    $this->actingAs($this->owner);
    foreach (['/budgets' => 'budgets/Index', '/budgets/categories/new' => 'budgets/Category', '/budgets/categories/'.$this->category->id.'/revise' => 'budgets/Category', '/budgets/schedules/new' => 'budgets/Schedule', '/budgets/schedules/'.$this->rule->id.'/revise' => 'budgets/Schedule'] as $url => $component) {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }
    $this->get('/budgets')->assertInertia(fn (Assert $page) => $page->where('budget.occurrences.0.expected.minor', '14725')->where('budget.rules.0.id', (string) $this->rule->id)->missing('budget.rules.0.owner_id')->missing('budget.rules.0.expected_minor'));
    $this->get('/budgets?month=0001-01')->assertInertia(fn (Assert $page) => $page->where('budget.previous_month', null));
    $this->get('/budgets?month=9998-12')->assertInertia(fn (Assert $page) => $page->where('budget.next_month', null));
});

test('lost allocation response recovers same saved identity and conflicts on changed details', function () {
    $this->actingAs($this->owner);
    $first = $this->postJson('/budgets/allocations', $this->allocation)->assertCreated();
    $this->postJson('/budgets/allocations', $this->allocation)->assertCreated()->assertExactJson($first->json());
    $this->postJson('/budgets/allocations', [...$this->allocation, 'amount' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $release = ['idempotency_key' => 'http-release-match', 'reason' => 'Synthetic explicit release'];
    $saved = $this->postJson('/budgets/allocations/'.$first->json('id').'/release', $release)->assertCreated();
    $this->postJson('/budgets/allocations/'.$first->json('id').'/release', $release)->assertCreated()->assertExactJson($saved->json());
    $this->assertDatabaseCount('planning_allocations', 1);
    $this->assertDatabaseCount('planning_releases', 1);
    $this->assertDatabaseCount('journal_entries', 1);
});

test('foreign planning and actual identities remain hidden before cached recovery for any user', function (bool $admin) {
    $match = app(BudgetService::class)->allocate($this->owner, 'budget-http-match', (string) $this->rule->id, '2026-10-18', (string) $this->journal->id, '147.25');
    $other = User::factory()->create(['is_admin' => $admin]);
    $this->actingAs($other);
    $this->get('/budgets')->assertInertia(fn (Assert $page) => $page->has('budget.categories', 0)->has('budget.activity', 0)->has('budget.occurrences', 0));
    foreach (['/budgets/categories/'.$this->category->id.'/revise', '/budgets/schedules/'.$this->rule->id.'/revise'] as $url) {
        $this->get($url)->assertNotFound();
    }
    $this->postJson('/budgets/allocations', $this->allocation)->assertNotFound();
    $this->postJson('/budgets/classifications', ['idempotency_key' => 'http-foreign-classify', 'journal_id' => (string) $this->journal->id, 'category_id' => (string) $this->category->id, 'reason' => 'Synthetic attempt'])->assertNotFound();
    $this->postJson('/budgets/allocations/'.$match->id.'/release', ['idempotency_key' => 'foreign-release-key', 'reason' => 'Synthetic attempt'])->assertNotFound();
    $this->postJson('/budgets/categories/'.$this->category->id.'/revise', [...B::categoryInput(), 'reason' => 'Synthetic attempt', 'idempotency_key' => 'foreign-category-key'])->assertNotFound();
    $this->postJson('/budgets/schedules', [...B::input($this->category), 'idempotency_key' => 'foreign-schedule-key'])->assertNotFound();
    $this->postJson('/budgets/schedules/'.$this->rule->id.'/revise', [...B::input($this->category), 'reason' => 'Synthetic attempt', 'cutover_date' => '2026-10-18', 'idempotency_key' => 'foreign-rule-key'])->assertNotFound();
    $ownCat = B::category($other);
    $ownRule = B::rule($other, $ownCat);
    $this->postJson('/budgets/allocations', [...$this->allocation, 'schedule_id' => (string) $ownRule->id])->assertNotFound();
    $this->assertDatabaseCount('planning_allocations', 1);
})->with([false, true]);

test('strict schedule inputs and forged fields are rejected without partial planning writes', function (string $field, mixed $value) {
    $this->actingAs($this->owner)->postJson('/budgets/schedules', [...B::input($this->category), $field => $value, 'idempotency_key' => 'strict-schedule-key'])->assertUnprocessable();
    $this->assertDatabaseCount('planning_schedules', 1);
    $this->assertDatabaseCount('journal_entries', 1);
})->with([['owner_id', '2'], ['expected_minor', '1'], ['amount', 147.25], ['amount', '1e2'], ['amount', '1.001'], ['amount', '0'], ['amount', '-1'], ['amount', '92233720368547758.08'], ['currency', 'XYZ'], ['first_due_date', '2026-02-30'], ['first_due_date', ['2026-10-18']], ['cadence', 'weekly'], ['salary', 'yes'], ['salary', true], ['month', '2026-13']]);

test('invalid return month prevents every write before redirects and malformed IDs stay controlled', function () {
    $this->actingAs($this->owner)->postJson('/budgets/categories', [...B::categoryInput(), 'month' => '0000-01', 'idempotency_key' => 'bad-month-category'])->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->assertDatabaseCount('planning_categories', 1);
    foreach (['0001', '9223372036854775808', 'abc'] as $id) {
        $this->get('/budgets/categories/'.$id.'/revise')->assertNotFound();
        $this->get('/budgets/schedules/'.$id.'/revise')->assertNotFound();
        $this->postJson('/budgets/allocations', [...$this->allocation, 'schedule_id' => $id])->assertNotFound();
    }
    $this->assertDatabaseCount('planning_allocations', 0);
});

test('planning-only retained history blocks profile deletion with controlled feedback and retained session', function () {
    $owner = User::factory()->create();
    B::category($owner);
    $this->actingAs($owner)->from('/settings/profile')->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password')->assertRedirect('/settings/profile');
    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh())->not->toBeNull();
});
