<?php

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FxFixtures;
use Tests\Support\GoalFixtures;
use Tests\Support\MarketFixtures as M;
use Tests\TestCase;

require_once __DIR__.'/../Support/MarketRace.php';
require_once __DIR__.'/../Support/MarketLeafRace.php';
uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo(new DateTimeImmutable('2026-10-23T12:00:00Z'));
    $this->owner = User::factory()->create();
    config(['market_data.mode' => 'mock']);
    Http::preventStrayRequests();
    Http::fake(['api.twelvedata.com/*' => fn ($r) => Http::response(M::fxBody($r['symbol']))]);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('concurrent refresh claims across blocked PostgreSQL backends fetch and admit one effective sample', function () {
    $service = app(MarketRefresh::class);
    $service->map($this->owner, 'market-race-owner-one', M::fxInput());
    $service->map(User::factory()->create(), 'market-race-owner-two', M::fxInput());
    $command = ['feed' => MarketFeed::fx('USD', 'QAR')->data(), 'window' => '2026-10-23'];
    $results = concurrentMarketRace([$command, $command]);
    expect(array_column($results, 'status'))->each->toBeIn(['accepted', 'deferred']);
    expect(DB::table('market_attempts')->count())->toBe(1)->and(DB::table('market_results')->count())->toBe(1);
    $this->assertDatabaseCount('fx_observations', 2);
    $this->assertDatabaseCount('market_admissions', 2);
    $this->assertDatabaseCount('journal_entries', 0);
});

test('different owners and feeds contend for the last shared provider credit', function () {
    $service = app(MarketRefresh::class);
    $quotaOwner = User::factory()->create();
    foreach ([['GBP', 'USD'], ['GBP', 'EUR'], ['GBP', 'JPY'], ['GBP', 'KWD'], ['GBP', 'NGN'], ['EUR', 'JPY'], ['EUR', 'KWD']] as [$base, $quote]) {
        $service->map($quotaOwner, 'quota-setup-'.$base.'-'.$quote, M::fxInput($base, $quote));
        expect($service->refresh(MarketFeed::fx($base, $quote), '2026-10-23')['status'])->toBe('accepted');
    }
    Http::assertSentCount(7);
    expect(DB::table('fx_observations')->where('owner_id', $quotaOwner->id)->count())->toBe(7);
    $service->map($this->owner, 'shared-credit-owner-one', M::fxInput());
    $service->map(User::factory()->create(), 'shared-credit-owner-two', M::fxInput('EUR', 'QAR'));
    $results = concurrentMarketRace(array_map(fn ($base) => ['feed' => MarketFeed::fx($base, 'QAR')->data(), 'window' => '2026-10-23'], ['USD', 'EUR']));
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['accepted', 'deferred']);
    $this->assertDatabaseCount('market_attempts', 8);
    $this->assertDatabaseCount('fx_observations', 8);
    expect(DB::table('fx_observations')->where('owner_id', '<>', $quotaOwner->id)->count())->toBe(1);
    expect(DB::table('operation_reservations')->count())->toBe(8);
    expect(array_sum(array_column($results, 'actual_fake_sends')))->toBe(1);
    expect(array_column($results, 'code'))->toContain('shared_credit_limit');
});

test('market evidence is immutable and populated downgrade refuses before DDL', function () {
    $service = app(MarketRefresh::class);
    $service->map($this->owner, 'market-immutability-map', M::fxInput());
    $service->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23');
    foreach (['market_mappings', 'market_attempts', 'market_results', 'market_admissions'] as $table) {
        foreach (['delete', 'update'] as $operation) {
            expect(fn () => DB::transaction(fn () => $operation === 'delete' ? DB::table($table)->delete() : DB::table($table)->update(['id' => DB::raw('id')])))->toThrow(PDOException::class, 'immutable');
        }
    }
    $migration = require database_path('migrations/2026_10_23_070000_create_market_refresh_evidence.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'downgrade refused');
    expect(Schema::hasTable('market_admissions'))->toBeTrue();
    $this->assertDatabaseCount('fx_observations', 1);
});

test('empty market schema roundtrip preserves manual rates prices and journal bytes', function () {
    $bank = FxFixtures::account($this->owner);
    GoalFixtures::cash($this->owner, $bank, '12.34');
    $before = DB::table('journal_entries')->get()->toJson();
    $migration = require database_path('migrations/2026_10_23_070000_create_market_refresh_evidence.php');
    $migration->down();
    expect(Schema::hasTable('market_results'))->toBeFalse();
    $migration->up();
    expect(DB::table('journal_entries')->get()->toJson())->toBe($before);
});

test('an expired worker cannot overwrite a newer committed winner and never holds provider locks during HTTP', function () {
    app(MarketRefresh::class)->map($this->owner, 'late-worker-mapping', M::fxInput());
    $results = expiredMarketLeafRace();
    expect($results['owner_lock_wait_observed'])->toBeTrue()->and($results['provider_lock_free_during_http'])->toBeTrue();
    expect((string) DB::table('fx_observations')->value('value'))->toBe('3.650000000000');
    $this->assertDatabaseCount('market_attempts', 2);
    $this->assertDatabaseCount('market_results', 2);
    $this->assertDatabaseCount('market_admissions', 1);
    $this->assertDatabaseCount('operation_reservations', 2);
    $this->assertDatabaseCount('journal_entries', 0);
    $this->travelTo(new DateTimeImmutable('2026-10-23T12:00:22Z'));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.twelvedata.com/*' => Http::response(M::fxBody())]);
    expect(app(MarketRefresh::class)->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23')['status'])->toBe('accepted');
    Http::assertNothingSent();
});
