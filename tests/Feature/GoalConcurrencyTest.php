<?php

use App\Domain\Ledger\PostingService;
use App\Domain\Planning\GoalService;
use App\Domain\Planning\GoalView;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\BudgetFixtures;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->owner = User::factory()->create();
    $this->account = Fx::account($this->owner);
    G::cash($this->owner, $this->account, '100');
    $this->goal = G::goal($this->owner, $this->account, ['opening' => '0']);
    $this->command = ['operation' => 'reserve', 'key' => 'goal-real-race', 'goal' => (string) $this->goal->id, 'amount' => '100'];
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
function concurrentGoalRace(int $ownerId, array $commands): array
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
                $service = app(GoalService::class);
                $journal = match ($command['operation']) {
                    'plan' => $service->plan($owner, $command['key'], $command['input']),
                    'withdraw' => app(PostingService::class)->record($owner, $command['key'], $command['input']),
                    'archive' => DB::transaction(function () use ($command, $owner) {
                        $a = Account::query()->ownedBy($owner)->whereKey($command['account'])->lockForUpdate()->firstOrFail();
                        $a->archived_at = now();
                        $a->save();

                        return (object) ['id' => $a->id, 'reference' => 'synthetic-archive'];
                    }),
                    'release' => $service->release($owner, $command['key'], $command['reservation'], 'Synthetic concurrent release'),
                    default => $service->reserve($owner, $command['key'], $command['goal'], $command['amount'], 'Synthetic concurrent claim'),
                };
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
        if (in_array('archive', array_column($commands, 'operation'), true)) {
            DB::table('accounts')->where('owner_id', $ownerId)->lockForUpdate()->get();
        }
        foreach ($children as $child) {
            fwrite($child['pipe'], "go\n");
        }
        $deadline = microtime(true) + 8;
        $waiting = 0;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', $backends)->where('wait_event_type', 'Lock')->whereIn('wait_event', ['advisory', 'transactionid'])->count();
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

test('two observed PostgreSQL backends return one reservation identity for the same key', function () {
    $r = concurrentGoalRace($this->owner->id, [$this->command, $this->command]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('goal_reservations', 1);
});
test('two concurrent reservations across goals cannot claim account cash twice', function () {
    $b = G::goal($this->owner, $this->account, ['opening' => '0']);
    $r = concurrentGoalRace($this->owner->id, [$this->command, [...$this->command, 'key' => 'second-goal-race', 'goal' => (string) $b->id]]);
    $s = array_column($r, 'status');
    sort($s);
    expect($s)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('goal_reservations', 1);
});
test('concurrent dedicated and reserved plans have one winner across the shared capacity boundary', function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $owner = User::factory()->create();
    $a = Fx::account($owner);
    G::cash($owner, $a, '100');
    $base = ['operation' => 'plan', 'key' => 'dedicated-race', 'input' => G::input($a, ['mode' => 'dedicated', 'opening' => '100'])];
    $b = ['operation' => 'plan', 'key' => 'reserved-race', 'input' => G::input($a, ['mode' => 'reserved', 'opening' => '100'])];
    $r = concurrentGoalRace($owner->id, [$base, $b]);
    $s = array_column($r, 'status');
    sort($s);
    expect($s)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('goal_plans', 1);
});
test('reservation racing a legitimate withdrawal never fabricates backing or rejects the ledger withdrawal', function () {
    $withdraw = ['operation' => 'withdraw', 'key' => 'withdraw-goal-race', 'input' => ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '100', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic concurrent withdrawal']];
    $r = concurrentGoalRace($this->owner->id, [$this->command, $withdraw]);
    expect($r[1]['status'])->toBe('success');
    $g = app(GoalView::class)->show($this->owner, (string) $this->goal->id, substr(now('UTC')->toDateString(), 0, 7))['goal'];
    expect($g['backed']['minor'])->toBe('0')->and($g['nominal']['minor'])->toBe(in_array($r[0]['status'], ['success']) ? '10000' : '0');
});
test('deferred direct reservation overcapacity fails outer commit and all goal evidence is immutable', function () {
    $r = app(GoalService::class)->reserve($this->owner, 'raw-goal-capacity', (string) $this->goal->id, '100', 'Synthetic claim');
    $copy = (array) $r;
    unset($copy['id']);
    $copy['reference'] = (string) Str::ulid();
    $copy['amount_minor'] = '1';
    expect(fn () => DB::transaction(fn () => DB::table('goal_reservations')->insert($copy)))->toThrow(PDOException::class);
    $this->assertDatabaseCount('goal_reservations', 1);
    foreach (['plans', 'reservations', 'requests'] as $t) {
        expect(fn () => DB::transaction(fn () => DB::table('goal_'.$t)->where('owner_id', $this->owner->id)->update(['owner_id' => $this->owner->id])))->toThrow(PDOException::class);
        expect(fn () => DB::transaction(fn () => DB::table('goal_'.$t)->where('owner_id', $this->owner->id)->delete()))->toThrow(PDOException::class);
    }
});
test('goal migration refuses retained history and empty roundtrip preserves M1 and M2-P1', function () {
    $m = require database_path('migrations/2026_10_18_110000_create_goal_protection.php');
    expect(fn () => $m->down())->toThrow(RuntimeException::class);
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $o = User::factory()->create();
    $a = Fx::account($o);
    G::cash($o, $a, '100');
    $cat = BudgetFixtures::category($o);
    BudgetFixtures::rule($o, $cat);
    $before = DB::table('journal_entries')->get()->toJson();
    $budgets = DB::table('planning_schedules')->get()->toJson();
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
    // Remove the later empty cash settings schema before this phase's downgrade.
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    expect(Schema::hasTable('goal_plans'))->toBeFalse();
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect(DB::table('journal_entries')->get()->toJson())->toBe($before)->and(DB::table('planning_schedules')->get()->toJson())->toBe($budgets);
});

test('archive and reservation serialize on the owned account row without stale grant', function () {
    $archive = ['operation' => 'archive', 'account' => (string) $this->account->id];
    $r = concurrentGoalRace($this->owner->id, [$this->command, $archive]);
    expect($r[1]['status'])->toBe('success');
    $g = app(GoalView::class)->show($this->owner, (string) $this->goal->id, substr(now('UTC')->toDateString(), 0, 7))['goal'];
    expect($g['account_archived'])->toBeTrue();
    expect(fn () => app(GoalService::class)->reserve($this->owner, 'after-archived-race', (string) $this->goal->id, '1', 'Synthetic unavailable grant'))->toThrow(ValidationException::class);
});
test('concurrent same identity changed payload conflicts and release/create reuses capacity once', function () {
    $r = concurrentGoalRace($this->owner->id, [$this->command, [...$this->command, 'amount' => '50']]);
    $s = array_column($r, 'status');
    sort($s);
    expect($s)->toBe(['success', 'validation']);
    $reservation = DB::table('goal_reservations')->first();
    $release = ['operation' => 'release', 'key' => 'release-race', 'reservation' => (string) $reservation->id];
    $r = concurrentGoalRace($this->owner->id, [$release, [...$this->command, 'key' => 'after-release-race']]);
    expect($r[0]['status'])->toBe('success');
    $sum = DB::table('goal_reservations as r')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_releases as x')->whereColumn('x.reservation_id', 'r.id'))->sum('amount_minor');
    expect((int) $sum)->toBeLessThanOrEqual(10000);
});
test('outer transaction failure rolls back goal claim and durable request together', function () {
    expect(fn () => DB::transaction(function () {
        app(GoalService::class)->reserve($this->owner, 'outer-goal-rollback', (string) $this->goal->id, '100', 'Synthetic rollback');
        throw new RuntimeException('Synthetic outer rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic outer rollback');
    $this->assertDatabaseCount('goal_reservations', 0);
    expect(DB::table('goal_requests')->where('operation', 'reserve')->count())->toBe(0);
    app(GoalService::class)->reserve($this->owner, 'outer-goal-rollback', (string) $this->goal->id, '100', 'Synthetic rollback');
    $this->assertDatabaseCount('goal_reservations', 1);
});
