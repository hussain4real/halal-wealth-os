<?php

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use App\Jobs\RefreshMarketFeed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketFixtures as M;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-23T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->input = ['kind' => 'fx', 'base' => 'USD', 'quote' => 'QAR', 'symbol' => '', 'currency' => '', 'mic_code' => '', 'exchange_timezone' => '',
        'portfolio_account_id' => '', 'asset_id' => '', 'context' => '', 'raw_confirmed' => '', 'enabled' => '1', 'supersedes_id' => '', 'idempotency_key' => 'http-market-mapping'];
    Http::preventStrayRequests();
});

test('market status distinguishes disabled transport shared fetch and owned admission with honest daily evidence', function () {
    $p = M::price($this->owner);
    $this->actingAs($this->owner)->get('/market-data')->assertOk()->assertInertia(fn (Assert $p) => $p->component('market/Index')->where('mode', 'disabled')->where('mapping_count', 1)->where('mappings.0.run', null));
    config(['market_data.mode' => 'mock']);
    Http::fake(['api.twelvedata.com/*' => Http::response(M::priceBody('2026-10-10'))]);
    app(MarketRefresh::class)->refresh($p['feed'], '2026-10-23');
    $this->get('/market-data')->assertOk()->assertInertia(fn (Assert $p) => $p->where('mode', 'mock')->where('mappings.0.run.status', 'accepted')
        ->where('mappings.0.admission.outcome', 'accepted')->where('mappings.0.observation.stale', true)
        ->where('mappings.0.observation.observed_at', null)->where('mappings.0.observation.effective_at', null)
        ->where('mappings.0.observation.provider_time.source_date', '2026-10-10')->where('mappings.0.observation.provider_time.received_at', '2026-10-23T12:00:00Z'));
});

test('mapping HTTP exact committed retry recovers after disable without a second version', function () {
    $first = $this->actingAs($this->owner)->postJson('/market-data', $this->input)->assertCreated()->json('id');
    $disable = [...$this->input, 'kind' => 'disable', 'enabled' => '0', 'supersedes_id' => $first, 'idempotency_key' => 'http-disable-mapping'];
    $this->postJson('/market-data', $disable)->assertCreated();
    $this->postJson('/market-data', $this->input)->assertCreated()->assertJsonPath('id', $first);
    $this->assertDatabaseCount('market_mappings', 2);
    $this->get('/market-data')->assertOk()->assertInertia(fn (Assert $p) => $p->where('mapping_count', 1)->where('mappings.0.enabled', false));
    $this->postJson('/market-data', [...$this->input, 'quote' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
});

test('market routes reject guests foreign owner and admin target reads and writes', function () {
    $this->get('/market-data')->assertRedirect('/login');
    $this->postJson('/market-data', $this->input)->assertUnauthorized();
    $foreign = User::factory()->create();
    $p = M::price($foreign);
    $id = DB::table('market_mappings')->where('owner_id', $foreign->id)->value('id');
    foreach ([$this->owner, User::factory()->create(['is_admin' => true])] as $actor) {
        $this->actingAs($actor)->get('/market-data?edit='.$id)->assertNotFound();
        $this->get('/market-data?portfolio='.$p['account']->id)->assertNotFound();
        $this->postJson('/market-data', [...$this->input, 'kind' => 'disable', 'enabled' => '0', 'supersedes_id' => (string) $id])->assertNotFound();
        $this->get('/market-data')->assertOk()->assertInertia(fn (Assert $p) => $p->where('mapping_count', 0)->has('accounts', 0)->has('assets', 0));
    }
});

test('mapping price form uses owned current history and rejects stale or forged confirmation', function () {
    $p = M::price($this->owner);
    $id = DB::table('market_mappings')->value('id');
    $page = $this->actingAs($this->owner)->get('/market-data?edit='.$id)->assertOk();
    $page->assertInertia(fn (Assert $page) => $page->where('defaults.kind', 'price')->where('defaults.raw_confirmed', '')->where('defaults.portfolio_account_id', (string) $p['account']->id));
    $input = [...$this->input, ...json_decode(DB::table('market_mappings')->value('request_payload'), true), 'idempotency_key' => 'http-new-price-version', 'supersedes_id' => (string) $id, 'context' => 'stale'];
    $this->postJson('/market-data', $input)->assertUnprocessable()->assertJsonValidationErrors('raw_confirmed');
    $this->postJson('/market-data', [...$this->input, 'owner_id' => (string) $this->owner->id])->assertUnprocessable()->assertJsonValidationErrors('mapping');
    $this->assertDatabaseCount('market_mappings', 1);
});

test('market account and asset search pagination retains selected targets beyond a page', function () {
    $p = M::price($this->owner);
    for ($i = 0; $i < 21; $i++) {
        P::link($this->owner, P::account($this->owner));
        P::asset($this->owner, ['currency' => 'USD']);
    }
    $id = DB::table('market_mappings')->value('id');
    $this->actingAs($this->owner)->get('/market-data?edit='.$id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts', 21)->has('assets', 21)
        ->where('defaults.asset_id', (string) $p['asset']->id)->where('account_next', fn ($v) => is_string($v))->where('asset_next', fn ($v) => is_string($v)));
    $this->get('/market-data?q=not-present')->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts', 0)->has('assets', 0));
});

test('scheduler remains inert by default and dispatches one job per current shared feed in mock mode', function () {
    Queue::fake();
    $service = app(MarketRefresh::class);
    $service->map($this->owner, 'schedule-map-one', M::fxInput());
    $other = User::factory()->create();
    $service->map($other, 'schedule-map-two', M::fxInput());
    $this->artisan('market:refresh')->assertSuccessful();
    Queue::assertNothingPushed();
    config(['market_data.mode' => 'mock']);
    $this->artisan('market:refresh')->assertSuccessful();
    Queue::assertPushed(RefreshMarketFeed::class, 1);
    Queue::assertPushed(RefreshMarketFeed::class, fn ($job) => $job->window === '2026-10-23' && $job->feed == MarketFeed::fx('USD', 'QAR')->data());
    Http::assertNothingSent();
});

test('job redelivery resumes missing owner fanout from committed sample without another request', function () {
    $service = app(MarketRefresh::class);
    $service->map($this->owner, 'fanout-map-one', M::fxInput());
    config(['market_data.mode' => 'mock']);
    Http::fake(['api.twelvedata.com/*' => Http::response(M::fxBody())]);
    $job = new RefreshMarketFeed(MarketFeed::fx('USD', 'QAR')->data(), '2026-10-23');
    $job->handle($service);
    $other = User::factory()->create();
    $service->map($other, 'fanout-map-two', M::fxInput());
    $job->handle($service);
    $job->handle($service);
    Http::assertSentCount(1);
    $this->assertDatabaseCount('fx_observations', 2);
    $this->assertDatabaseCount('market_admissions', 2);
    $this->assertDatabaseCount('journal_entries', 0);
});
