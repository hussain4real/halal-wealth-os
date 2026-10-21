<?php

use App\Domain\Reporting\InvestmentValuation;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PortfolioFixtures as P;
use Tests\Support\ValuationFixtures as V;
use Tests\TestCase;

require_once __DIR__.'/../Support/ValuationRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->account = P::account($this->owner);
    $this->p = P::link($this->owner, $this->account);
    $this->asset = P::asset($this->owner);
    P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '2', 'unit_price' => ''])]);
    P::mode($this->owner, $this->p);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
test('duplicate price requests on separate contending PostgreSQL connections retain one version', function () {
    $cmd = ['operation' => 'price', 'key' => 'synthetic-racing-price', 'input' => V::input($this->p, $this->asset)];
    $r = concurrentValuationRace($this->owner->id, [$cmd, $cmd]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('portfolio_prices', 1);
});
test('competing corrections create one retained successor without branching', function () {
    $first = V::price($this->owner, $this->p, $this->asset);
    $input = V::input($this->p, $this->asset, ['supersedes_id' => (string) $first->id]);
    $r = concurrentValuationRace($this->owner->id, [['operation' => 'price', 'key' => 'synthetic-price-correction-a', 'input' => $input], ['operation' => 'price', 'key' => 'synthetic-price-correction-b', 'input' => $input]]);
    $statuses = array_column($r, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('portfolio_prices', 2);
});
test('duplicate saved-run requests recover one committed immutable receipt', function () {
    V::price($this->owner, $this->p, $this->asset);
    $cmd = ['operation' => 'save', 'key' => 'synthetic-racing-saved-run', 'cutoff' => now('UTC')->format('Y-m-d\TH:i:s\Z')];
    $r = concurrentValuationRace($this->owner->id, [$cmd, $cmd]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('investment_valuation_runs', 1);
});
test('repeatable read does not mix cache dependencies or prices across a concurrent correction commit', function () {
    $first = V::price($this->owner, $this->p, $this->asset, ['unit_price' => '3']);
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Required process barrier unavailable');
    }
    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('Required fork failed');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        DB::purge();
        stream_set_timeout($pair[1], 20);
        try {
            $paused = false;
            DB::listen(function ($query) use ($pair, &$paused) {
                if (! $paused && str_contains($query->sql, 'from "portfolio_prices"')) {
                    $paused = true;
                    fwrite($pair[1], json_encode(['backend' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, 'isolation' => DB::selectOne('SHOW transaction_isolation')->transaction_isolation])."\n");
                    if (trim((string) fgets($pair[1])) !== 'go') {
                        throw new RuntimeException('Reader barrier missing');
                    }
                }
            });
            $r = app(InvestmentValuation::class)->capture(User::findOrFail($this->owner->id), Currency::QAR, now('UTC')->format('Y-m-d\TH:i:s\Z'));
            fwrite($pair[1], json_encode(['status' => 'success', 'minor' => $r['ending']['total']['minor'], 'price_id' => $r['ending']['components'][1]['price']['id'], 'key' => $r['cache_key']])."\n");
        } catch (Throwable $e) {
            fwrite($pair[1], json_encode(['status' => 'error', 'message' => $e->getMessage()])."\n");
        }
        DB::disconnect();
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 20);
    $ready = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
    try {
        V::price($this->owner, $this->p, $this->asset, ['supersedes_id' => (string) $first->id, 'unit_price' => '5']);
    } finally {
        fwrite($pair[0], "go\n");
    }
    $result = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);
    expect($ready['isolation'])->toBe('repeatable read')->and($result['status'])->toBe('success')->and($result['minor'])->toBe('600')->and($result['price_id'])->toBe((string) $first->id);
    $new = app(InvestmentValuation::class)->capture($this->owner, Currency::QAR, now('UTC')->format('Y-m-d\TH:i:s\Z'));
    expect($new['ending']['total']['minor'])->toBe('1000')->and($new['cache_key'])->not->toBe($result['key']);
});
test('price and saved evidence reject committed mutation and destructive downgrade before DDL', function () {
    $p = V::price($this->owner, $this->p, $this->asset);
    $r = app(InvestmentValuation::class)->save($this->owner, 'synthetic-retained-run', Currency::QAR, now('UTC')->format('Y-m-d\TH:i:s\Z'));
    foreach ([['portfolio_prices', $p->id], ['investment_valuation_runs', $r->id]] as [$table, $id]) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(PDOException::class);
    }
    $decisions = require database_path('migrations/2026_10_21_064500_create_decision_records.php');
    $decisions->down();
    $purification = require database_path('migrations/2026_10_20_073000_create_purification_records.php');
    $purification->down();
    $m = require database_path('migrations/2026_10_19_000001_create_investment_valuation_evidence.php');
    expect(fn () => $m->down())->toThrow(RuntimeException::class, 'before DDL');
    expect(Schema::hasTable('portfolio_prices'))->toBeTrue();
});
test('empty valuation migration roundtrip preserves pre-existing immutable opening and ledger rows', function () {
    $decisions = require database_path('migrations/2026_10_21_064500_create_decision_records.php');
    $decisions->down();
    $purification = require database_path('migrations/2026_10_20_073000_create_purification_records.php');
    $purification->down();
    $m = require database_path('migrations/2026_10_19_000001_create_investment_valuation_evidence.php');
    $rows = DB::table('portfolio_opening_rows')->orderBy('id')->get()->toJson();
    $accounts = DB::table('accounts')->orderBy('id')->get()->toJson();
    $m->down();
    expect(Schema::hasTable('portfolio_prices'))->toBeFalse();
    $m->up();
    $purification->up();
    $decisions->up();
    expect(DB::table('portfolio_opening_rows')->orderBy('id')->get()->toJson())->toBe($rows)->and(DB::table('accounts')->orderBy('id')->get()->toJson())->toBe($accounts);
});
