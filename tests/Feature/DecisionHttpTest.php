<?php

use App\Domain\Decisions\DecisionRead;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssessmentFixtures as A;
use Tests\Support\DecisionFixtures as F;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
});
test('owner can save read revise reserve release and export a decision without posting a trade', function () {
    $this->get('/opportunities')->assertInertia(fn (Assert $p) => $p->component('opportunities/Index')->has('opportunities', 0));
    $this->get('/opportunities/new')->assertInertia(fn (Assert $p) => $p->component('opportunities/Form')->where('draft', null));
    $input = ['idempotency_key' => 'synthetic-http-plan', ...F::input()];
    $id = $this->postJson('/opportunities', $input)->assertCreated()->json('id');
    $account = Fx::account($this->owner, Currency::NGN);
    G::cash($this->owner, $account, '1000');
    $goal = G::goal($this->owner, $account, ['opening' => '0']);
    $before = DB::table('postings')->get()->toJson();
    $reserve = ['idempotency_key' => 'synthetic-http-reserve', 'opportunity_id' => $id, 'tranche_key' => 'initial', 'goal_id' => (string) $goal->id, 'amount' => '10', 'reason' => 'Synthetic planned cash'];
    $r = $this->postJson('/opportunities/reservations', $reserve)->assertCreated()->json('id');
    $next = $this->postJson('/opportunities', [...$input, 'idempotency_key' => 'synthetic-http-revision', 'prior_id' => $id, 'risks' => 'Revised risk'])->assertCreated()->json('id');
    $this->get('/opportunities/'.$id)->assertInertia(fn (Assert $p) => $p->component('opportunities/Show')->where('is_current', false)->where('current_id', $next)->has('history', 2)->where('reservations.0.opportunity_id', $id)->where('reservations.0.backing.amount', '10.00'));
    $this->get('/opportunities/'.$id.'/revise')->assertInertia(fn (Assert $p) => $p->where('stale', true)->where('draft.prior_id', $id));
    $this->postJson('/opportunities/reservations', $reserve)->assertCreated()->assertJsonPath('id', $r);
    $this->postJson('/opportunities/reservations/'.$r.'/release', ['idempotency_key' => 'synthetic-http-release', 'reason' => 'Synthetic release'])->assertCreated();
    $this->get('/opportunities/export')->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(2, 'opportunities')->assertJsonCount(1, 'reservations')->assertJsonCount(1, 'reservation_releases');
    expect(DB::table('postings')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('investment_events', 0);
});
test('every foreign owner and administrator identifier is isolated on detail drafts links reservations release and export', function (bool $admin) {
    $asset = P::asset($this->owner, ['currency' => 'NGN']);
    $p = F::plan($this->owner, ['asset_id' => (string) $asset->id]);
    $account = Fx::account($this->owner, Currency::NGN);
    G::cash($this->owner, $account, '1000');
    $goal = G::goal($this->owner, $account, ['opening' => '0']);
    $r = F::reserve($this->owner, $p, $goal);
    $foreign = User::factory()->create(['is_admin' => $admin]);
    $this->actingAs($foreign);
    foreach (['/opportunities/'.$p->id, '/opportunities/'.$p->id.'/revise'] as $url) {
        $this->getJson($url)->assertNotFound();
    }
    $this->postJson('/opportunities', ['idempotency_key' => 'synthetic-foreign-prior', ...F::input(['prior_id' => (string) $p->id])])->assertNotFound();
    $this->postJson('/opportunities', ['idempotency_key' => 'synthetic-foreign-asset', ...F::input(['asset_id' => (string) $asset->id])])->assertNotFound();
    $this->postJson('/opportunities/reservations', ['idempotency_key' => 'synthetic-foreign-reserve', 'opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'goal_id' => (string) $goal->id, 'amount' => '1', 'reason' => 'Synthetic foreign request'])->assertNotFound();
    $this->postJson('/opportunities/reservations/'.$r->id.'/release', ['idempotency_key' => 'synthetic-foreign-release', 'reason' => 'Synthetic foreign request'])->assertNotFound();
    $this->postJson('/opportunities/event-links', ['idempotency_key' => 'synthetic-foreign-link', 'opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'event_id' => '1', 'reason' => 'Synthetic foreign request'])->assertNotFound();
    $own = F::plan($foreign);
    $this->postJson('/opportunities/reservations', ['idempotency_key' => 'synthetic-foreign-goal', 'opportunity_id' => (string) $own->id, 'tranche_key' => 'initial', 'goal_id' => (string) $goal->id, 'amount' => '1', 'reason' => 'Synthetic foreign goal'])->assertNotFound();
    $this->get('/opportunities/export')->assertJsonCount(1, 'opportunities')->assertJsonCount(0, 'reservations')->assertJsonCount(0, 'actual_event_links')->assertJsonPath('opportunities.0.id', (string) $own->id);
})->with([false, true]);
test('exact asset Shariah summary stays separate and actual receipt links back to its original thesis version', function () {
    $asset = P::asset($this->owner, ['currency' => 'NGN']);
    $other = P::asset($this->owner, ['prior_id' => (string) $asset->id, 'currency' => 'NGN']);
    A::record($this->owner, $other);
    $p = F::plan($this->owner, ['asset_id' => (string) $asset->id]);
    $this->get('/opportunities/'.$p->id)->assertInertia(fn (Assert $p) => $p->where('assessment.summary.classification', 'pending_review'));
    $account = P::account($this->owner, Currency::NGN);
    $portfolio = P::link($this->owner, $account);
    I::fund($this->owner, $account);
    $event = I::event($this->owner, $portfolio, $asset);
    $this->postJson('/opportunities/event-links', ['idempotency_key' => 'synthetic-http-event-link', 'opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'event_id' => (string) $event->id, 'reason' => 'Synthetic actual attribution'])->assertCreated();
    F::plan($this->owner, ['prior_id' => (string) $p->id, 'asset_id' => (string) $asset->id, 'rationale' => 'Later thesis']);
    $this->get('/portfolio/'.$portfolio->id.'/events/'.$event->id)->assertInertia(fn (Assert $v) => $v->where('event.decision.opportunity_id', (string) $p->id));
});
test('bounded current lists retain complete export and historical thesis and tranche totals across pages', function () {
    for ($i = 0; $i < 23; $i++) {
        F::plan($this->owner, ['name' => 'Synthetic opportunity '.$i]);
    }
    $p = F::plan($this->owner);
    for ($i = 0; $i < 22; $i++) {
        $p = F::plan($this->owner, ['prior_id' => (string) $p->id, 'reason' => 'Synthetic revision '.$i]);
    }
    $this->get('/opportunities')->assertInertia(fn (Assert $p) => $p->has('opportunities', 20)->where('next', fn ($n): bool => is_string($n)));
    $this->get('/opportunities/'.$p->id)->assertInertia(fn (Assert $p) => $p->has('history', 20)->where('history_next', fn ($n): bool => is_string($n)));
    $this->get('/opportunities/export')->assertJsonCount(46, 'opportunities');
    expect(app(DecisionRead::class)->index($this->owner, null, 'closed')['opportunities'])->toBe([]);
});
test('guest unverified production two-factor and malformed cursor gates apply to opportunities', function () {
    auth()->logout();
    $this->get('/opportunities')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create());
    $this->get('/opportunities')->assertRedirect('/email/verify');
    $this->actingAs($this->owner);
    $this->getJson('/opportunities?status[]=planned')->assertUnprocessable();
    $this->getJson('/opportunities?before[]=1')->assertNotFound();
    $this->app->detectEnvironment(fn () => 'production');
    $this->get('/opportunities')->assertRedirect('/settings/security');
});
