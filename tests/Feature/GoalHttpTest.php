<?php

use App\Domain\Planning\GoalService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create(['is_admin' => true]);
    $this->account = Fx::account($this->owner);
    G::cash($this->owner, $this->account);
    $this->goal = G::goal($this->owner, $this->account);
});
test('owner goal views include separate cash reservation plan and missing coverage measures', function () {
    $this->actingAs($this->owner)->get('/goals')->assertOk()->assertInertia(fn (Assert $p) => $p->component('goals/Index')->has('goals', 1)->where('goals.0.backed.minor', '480000')->where('goals.0.projection.deposits', '12'));
    $this->get('/goals/'.$this->goal->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('goals/Show')->where('goal.nominal.minor', '480000')->where('goal.projection.coverage_months', null)->has('movements', 1));
    $this->get('/goals/new')->assertOk();
    $this->get('/goals/'.$this->goal->id.'/revise')->assertOk();
});
test('owner B including admin cannot access goal backing links releases revisions or cached requests', function () {
    $r = DB::table('goal_reservations')->first();
    $p = DB::table('postings')->where('account_id', $this->account->id)->first();
    $this->actingAs($this->other)->get('/goals/'.$this->goal->id)->assertNotFound();
    $this->get('/goals/'.$this->goal->id.'/revise')->assertNotFound();
    $this->postJson('/goals/'.$this->goal->id.'/reservations', ['idempotency_key' => 'owner-isolation-reserve', 'amount' => '1', 'reason' => 'Synthetic isolation'])->assertNotFound();
    $this->postJson('/goals/'.$this->goal->id.'/links', ['idempotency_key' => 'owner-isolation-link', 'posting_id' => (string) $p->id, 'reason' => 'Synthetic isolation'])->assertNotFound();
    $this->postJson('/goals/reservations/'.$r->id.'/release', ['idempotency_key' => 'owner-isolation-release', 'reason' => 'Synthetic isolation'])->assertNotFound();
    $this->postJson('/goals', ['idempotency_key' => 'owner-isolation-plan', ...G::input($this->account)])->assertNotFound();
    $this->get('/goals')->assertInertia(fn (Assert $p) => $p->has('goals', 0));
});
test('reserve validation keeps exact string input and rejects extra forged financial fields', function () {
    $this->actingAs($this->owner)->postJson('/goals/'.$this->goal->id.'/reservations', ['idempotency_key' => 'http-no-capacity', 'amount' => '1', 'reason' => 'Synthetic capacity'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson('/goals/'.$this->goal->id.'/reservations', ['idempotency_key' => 'http-money-type', 'amount' => 1, 'reason' => 'Synthetic integer'])->assertUnprocessable();
    $this->postJson('/goals', ['idempotency_key' => 'http-forged-reminder', ...G::input($this->account, ['opening' => '0']), 'reminders' => '1'])->assertUnprocessable()->assertJsonValidationErrors('reminders');
});
test('JSON request retry returns one saved identity without duplicating an earmark or journal', function () {
    $r = DB::table('goal_reservations')->first();
    app(GoalService::class)->release($this->owner, 'http-release-existing', (string) $r->id, 'Synthetic recovery setup');
    $payload = ['idempotency_key' => 'http-reserve-safe', 'amount' => '4800', 'reason' => 'Synthetic full claim'];
    $a = $this->actingAs($this->owner)->postJson('/goals/'.$this->goal->id.'/reservations', $payload)->assertCreated();
    $b = $this->postJson('/goals/'.$this->goal->id.'/reservations', $payload)->assertCreated();
    expect($a->json('id'))->toBe($b->json('id'));
    $this->assertDatabaseCount('goal_reservations', 2);
    $this->assertDatabaseCount('journal_entries', 1);
});
test('goals are inaccessible to guests and unverified owners', function () {
    auth()->logout();
    $this->get('/goals')->assertRedirect('/login');
    $u = User::factory()->unverified()->create();
    $this->actingAs($u)->get('/goals')->assertRedirect('/email/verify');
});

test('revision recovery keeps original route identity after response loss and replays one version', function () {
    $input = G::input($this->account, ['contribution' => '800', 'reason' => 'Synthetic revised plan']);
    $url = '/goals/'.$this->goal->id.'/revise';
    $payload = ['idempotency_key' => 'lost-revision-http', ...$input];
    $first = $this->actingAs($this->owner)->postJson($url, $payload)->assertCreated();
    $this->get($url)->assertInertia(fn (Assert $p) => $p->where('operation_goal', (string) $this->goal->id)->where('prior.id', $first->json('id')));
    $second = $this->postJson($url, $payload)->assertCreated();
    expect($second->json('id'))->toBe($first->json('id'));
    $this->assertDatabaseCount('goal_plans', 2);
});
