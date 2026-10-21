<?php

use App\Domain\Ledger\PostingService;
use App\Domain\Planning\BudgetService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\FxFixtures as Fx;
use Tests\TestCase;

// Real outer commits on the guarded, separately inspected disposable PostgreSQL.
uses(TestCase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->owner = User::factory()->create();
    $this->account = Fx::account($this->owner);
    $this->category = B::category($this->owner);
    $this->rule = B::rule($this->owner, $this->category, ['first_due_date' => '2026-10-20']);
    $this->journal = app(PostingService::class)->record($this->owner, 'budget-race-posted', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '147.25', 'effective_date' => '2026-10-18', 'description' => 'Synthetic concurrency receipt']);
    $this->command = ['operation' => 'allocate', 'schedule' => (string) $this->rule->id, 'journal' => (string) $this->journal->id, 'key' => 'budget-real-race'];
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

/**
 * The parent holds the actual DB advisory lock until BOTH independent backends
 * are observed waiting on it. Serial execution cannot satisfy this barrier.
 *
 * @param  list<array<string, mixed>>  $commands
 * @return list<array<string, mixed>>
 */
function concurrentBudgetRace(int $ownerId, array $commands): array
{
    DB::disconnect();
    $children = [];
    foreach ($commands as $command) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Required UNIX process barrier unavailable');
        }
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new RuntimeException('Required fork failed');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            try {
                DB::purge();
                $backend = DB::selectOne('select pg_backend_pid() as pid')->pid;
                fwrite($pair[1], json_encode(['backend' => $backend], JSON_THROW_ON_ERROR)."\n");
                if (trim((string) fgets($pair[1])) !== 'go') {
                    throw new RuntimeException('Barrier not released');
                }
                $owner = User::query()->findOrFail($ownerId);
                $service = app(BudgetService::class);
                $journal = $command['operation'] === 'revise'
                    ? $service->schedule($owner, $command['key'], $command['input'], $command['schedule'])
                    : $service->allocate($owner, $command['key'], $command['schedule'], '2026-10-20', $command['journal'], '147.25');
                $result = ['status' => 'success', 'id' => $journal->id, 'reference' => $journal->reference, 'backend' => $backend];
            } catch (ValidationException $error) {
                $result = ['status' => 'validation', 'errors' => $error->errors()];
            } catch (Throwable $error) {
                $result = ['status' => 'error', 'type' => $error::class, 'message' => $error->getMessage()];
            }
            fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fclose($pair[1]);
            DB::disconnect();
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 15);
        $children[] = ['pid' => $pid, 'pipe' => $pair[0]];
    }
    $backends = [];
    foreach ($children as $child) {
        $ready = json_decode((string) fgets($child['pipe']), true, flags: JSON_THROW_ON_ERROR);
        $backends[] = $ready['backend'];
    }
    expect(count(array_unique($backends)))->toBe(count($commands));
    DB::beginTransaction();
    try {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['hwo:posting:owner:'.$ownerId]);
        foreach ($children as $child) {
            fwrite($child['pipe'], "go\n");
        }
        $deadline = microtime(true) + 8;
        $waiting = 0;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', $backends)->where('wait_event_type', 'Lock')->where('wait_event', 'advisory')->count();
            if ($waiting === count($children)) {
                break;
            }usleep(10000);
        } while (microtime(true) < $deadline);
        expect($waiting)->toBe(count($commands));
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
    $results = [];
    foreach ($children as $child) {
        $results[] = json_decode((string) fgets($child['pipe']), true, flags: JSON_THROW_ON_ERROR);
        fclose($child['pipe']);
        pcntl_waitpid($child['pid'], $status);
        expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
    }
    expect(array_column($results, 'status'))->not->toContain('error');

    return $results;
}

test('two waiting PostgreSQL backends recover one durable allocation for the same key', function () {
    $r = concurrentBudgetRace($this->owner->id, [$this->command, $this->command]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('planning_allocations', 1);
    $this->assertDatabaseCount('planning_occurrences', 1);
    $this->assertDatabaseCount('journal_entries', 1);
});

test('concurrent allocations cannot spend actual or occurrence capacity twice', function (string $shared) {
    $second = [...$this->command, 'key' => 'budget-second-race'];
    if ($shared === 'actual') {
        $second['schedule'] = (string) B::rule($this->owner, $this->category, ['first_due_date' => '2026-10-20'])->id;
    } else {
        $second['journal'] = (string) app(PostingService::class)->record($this->owner, 'budget-second-actual', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '147.25', 'effective_date' => '2026-10-18', 'description' => 'Synthetic second receipt'])->id;
    }
    $r = concurrentBudgetRace($this->owner->id, [$this->command, $second]);
    $states = array_column($r, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('planning_allocations', 1);
})->with(['actual', 'occurrence']);

test('future revision and match contend on one owner boundary with one valid winner', function () {
    $revision = ['operation' => 'revise', 'schedule' => (string) $this->rule->id, 'key' => 'budget-race-revise', 'input' => B::input($this->category, ['first_due_date' => '2026-10-21', 'cutover_date' => '2026-10-19', 'reason' => 'Synthetic concurrent change'])];
    $r = concurrentBudgetRace($this->owner->id, [$this->command, $revision]);
    $states = array_column($r, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    expect(DB::table('planning_allocations')->count() + DB::table('planning_schedules')->whereNotNull('supersedes_id')->count())->toBe(1);
    $this->assertDatabaseCount('journal_entries', 1);
});

test('deferred allocation guards reject raw overcapacity at outer commit and retain immutable evidence', function () {
    $a = app(BudgetService::class)->allocate($this->owner, 'budget-guard-match', (string) $this->rule->id, '2026-10-20', (string) $this->journal->id, '147.25');
    $copy = (array) $a;
    unset($copy['id']);
    $copy['reference'] = (string) Str::ulid();
    $copy['amount_minor'] = '1';
    expect(fn () => DB::transaction(fn () => DB::table('planning_allocations')->insert($copy)))->toThrow(PDOException::class);
    $this->assertDatabaseCount('planning_allocations', 1);
    app(BudgetService::class)->release($this->owner, 'budget-guard-release', (string) $a->id, 'Synthetic audited release');
    foreach (['planning_categories', 'planning_schedules', 'planning_occurrences', 'planning_attributions', 'planning_allocations', 'planning_releases', 'planning_requests'] as $table) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('owner_id', $this->owner->id)->update(['owner_id' => $this->owner->id])))->toThrow(PDOException::class);
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('owner_id', $this->owner->id)->delete()))->toThrow(PDOException::class);
    }
    $this->assertDatabaseCount('journal_entries', 1);
});

test('planning downgrade refuses retained rows and an empty schema roundtrip preserves M1 journal bytes', function () {
    $migration = require database_path('migrations/2026_10_18_080000_create_budget_planning.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(Schema::hasTable('planning_allocations'))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    app(PostingService::class)->record($owner, 'budget-upgrade-journal', ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '17.35', 'effective_date' => '2026-10-18', 'description' => 'Synthetic upgrade evidence']);
    $before = DB::table('journal_entries')->orderBy('id')->get()->toJson();
    $legs = DB::table('postings')->orderBy('id')->get()->toJson();
    // Remove only the new empty dashboard schema before the historical phase boundary.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('dashboard_snapshots'))->toBeFalse();
    // Remove the new empty decision schema before the historical downgrade.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('decision_opportunities'))->toBeFalse();
    // Remove only the later empty purification schema first.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('purification_obligations'))->toBeFalse();
    // Remove the later empty assessment schema before historical downgrade boundaries.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('shariah_assessments'))->toBeFalse();
    // Remove the later empty valuation schema before testing historical downgrade boundaries.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('portfolio_prices'))->toBeFalse();
    // Remove the empty investment event schema without changing historical journal assertions.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('investment_events'))->toBeFalse();
    // Remove the later empty portfolio schema before the historical phase schemas.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('portfolio_accounts'))->toBeFalse();
    // Remove the later empty cash settings and goal schemas before this downgrade.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('planning_categories'))->toBeFalse();
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect(DB::table('journal_entries')->orderBy('id')->get()->toJson())->toBe($before)->and(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($legs);
    $this->assertDatabaseCount('planning_categories', 0);
});
