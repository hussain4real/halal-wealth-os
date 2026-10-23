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
    foreach ([['GBP', 'USD'], ['GBP', 'EUR'], ['GBP', 'JPY'], ['GBP', 'KWD'], ['GBP', 'NGN'], ['EUR', 'JPY'], ['EUR', 'KWD']] as [$base, $quote]) {
        expect($service->refresh(MarketFeed::fx($base, $quote), '2026-10-23')['status'])->toBe('accepted');
    }
    $service->map($this->owner, 'shared-credit-owner-one', M::fxInput());
    $service->map(User::factory()->create(), 'shared-credit-owner-two', M::fxInput('EUR', 'QAR'));
    $results = concurrentMarketRace(array_map(fn ($base) => ['feed' => MarketFeed::fx($base, 'QAR')->data(), 'window' => '2026-10-23'], ['USD', 'EUR']));
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['accepted', 'deferred']);
    $this->assertDatabaseCount('market_attempts', 8);
    $this->assertDatabaseCount('fx_observations', 1);
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

test('an expired worker cannot overwrite a newer committed winner and never holds locks during HTTP', function () {
    app(MarketRefresh::class)->map($this->owner, 'late-worker-mapping', M::fxInput());
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Process barrier required.');
    }
    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('Worker fork required.');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        stream_set_timeout($pair[1], 15);
        try {
            DB::purge();
            $backend = DB::selectOne('select pg_backend_pid() as pid')->pid;
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(function () use ($pair, $backend) {
                fwrite($pair[1], json_encode(['fetching' => true, 'backend' => $backend])."\n");
                if (trim((string) fgets($pair[1])) !== 'release') {
                    throw new RuntimeException('Fetch barrier not released.');
                }
                $this->travelTo(new DateTimeImmutable('2026-10-23T12:00:22Z'));

                return Http::response(M::fxBody(value: '3.64'));
            });
            $result = app(MarketRefresh::class)->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23');
        } catch (Throwable $error) {
            $result = ['status' => 'error', 'message' => $error->getMessage()];
        }
        fwrite($pair[1], json_encode($result)."\n");
        fclose($pair[1]);
        DB::disconnect();
        exit(0);
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 15);
    try {
        $ready = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
        expect($ready['fetching'])->toBeTrue()->and(DB::selectOne('select pg_backend_pid() as pid')->pid)->not->toBe($ready['backend']);
        $this->travelTo(new DateTimeImmutable('2026-10-23T12:00:21Z'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.twelvedata.com/*' => Http::response(M::fxBody(value: '3.65'))]);
        expect(app(MarketRefresh::class)->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23')['status'])->toBe('accepted');
        fwrite($pair[0], "release\n");
        $late = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
        expect($late)->toMatchArray(['status' => 'late', 'code' => 'late_ignored']);
        expect((string) DB::table('fx_observations')->value('value'))->toBe('3.650000000000');
        $this->assertDatabaseCount('market_attempts', 2);
        $this->assertDatabaseCount('market_results', 2);
        $this->assertDatabaseCount('market_admissions', 1);
        expect(app(MarketRefresh::class)->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23')['status'])->toBe('accepted');
        Http::assertSentCount(1);
    } finally {
        fclose($pair[0]);
        pcntl_waitpid($pid, $exit);
    }
    expect(pcntl_wifexited($exit) && pcntl_wexitstatus($exit) === 0)->toBeTrue();
});
