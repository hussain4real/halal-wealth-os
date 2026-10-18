<?php

use App\Domain\Ledger\PostingService;
use App\Domain\Planning\AvailabilitySettings;
use App\Domain\Planning\CashAvailability;
use App\Domain\Planning\GoalService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AvailabilityFixtures as A;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->owner = User::factory()->create();
    $this->account = Fx::account($this->owner);
    G::cash($this->owner, $this->account, '100');
    $this->goal = G::goal($this->owner, $this->account, ['opening' => '0']);
    $this->settings = A::settings($this->owner, ['buffer' => '0']);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
function concurrentAvailabilityRace(int $ownerId, array $commands): array
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
                $journal = match ($command['operation']) {
                    'save' => app(AvailabilitySettings::class)->save($owner, $command['key'], $command['input']),
                    'atomic' => DB::transaction(function () use ($owner, $command) {
                        $row = app(GoalService::class)->reserve($owner, $command['key'], $command['goal'], '80', 'Synthetic atomic protection');
                        app(PostingService::class)->record($owner, $command['key'].'-draw', ['kind' => 'expense', 'account_id' => $command['account'], 'amount' => '20', 'effective_date' => '2026-10-18', 'description' => 'Synthetic atomic draw']);

                        return $row;
                    }),
                    default => (function () use ($owner) {
                        $s = app(CashAvailability::class)->capture($owner)['actual'];

                        return (object) ['id' => 'snapshot', 'reference' => 'readonly', 'cash' => $s['breakdown']['cash']['minor'], 'protected' => $s['breakdown']['protected']['minor'], 'raw' => $s['raw']['minor']];
                    })(),
                };
                $result = ['status' => 'success', 'id' => $journal->id, 'reference' => $journal->reference, 'backend' => $backend, 'snapshot' => isset($journal->cash) ? ['cash' => $journal->cash, 'protected' => $journal->protected, 'raw' => $journal->raw] : null];
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

test('two observed independent PostgreSQL backends return one settings version for one request', function () {
    $command = ['operation' => 'save', 'key' => 'cash-concurrent-same-key', 'input' => A::input(['prior_id' => (string) $this->settings->id, 'buffer' => '0'])];
    $r = concurrentAvailabilityRace($this->owner->id, [$command, $command]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('cash_availability_settings', 2);
});
test('distinct simultaneous settings revisions cannot both supersede one current version', function () {
    $command = ['operation' => 'save', 'key' => 'cash-concurrent-first', 'input' => A::input(['prior_id' => (string) $this->settings->id, 'buffer' => '0'])];
    $r = concurrentAvailabilityRace($this->owner->id, [$command, [...$command, 'key' => 'cash-concurrent-second']]);
    $states = array_column($r, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('cash_availability_settings', 2);
});
test('availability snapshot sees either side of atomic cash and protection changes never a mixed state', function () {
    $r = concurrentAvailabilityRace($this->owner->id, [['operation' => 'atomic', 'key' => 'cash-atomic-race', 'goal' => (string) $this->goal->id, 'account' => (string) $this->account->id], ['operation' => 'read']]);
    expect(array_column($r, 'status'))->toBe(['success', 'success']);
    expect($r[1]['snapshot'])->toBeIn([['cash' => '10000', 'protected' => '0', 'raw' => '10000'], ['cash' => '8000', 'protected' => '8000', 'raw' => '0']]);
    $after = app(CashAvailability::class)->capture($this->owner)['actual'];
    expect($after['breakdown']['cash']['minor'])->toBe('8000')->and($after['breakdown']['protected']['minor'])->toBe('8000')->and($after['raw']['minor'])->toBe('0');
});
test('outer transaction rollback retains neither settings version nor reservation side effect', function () {
    DB::beginTransaction();
    app(AvailabilitySettings::class)->save($this->owner, 'cash-outer-rollback', A::input(['prior_id' => (string) $this->settings->id, 'buffer' => '0']));
    app(GoalService::class)->reserve($this->owner, 'cash-outer-grant', (string) $this->goal->id, '10', 'Synthetic rollback');
    DB::rollBack();
    $this->assertDatabaseCount('cash_availability_settings', 1);
    $this->assertDatabaseCount('goal_reservations', 0);
    expect(app(CashAvailability::class)->capture($this->owner)['actual']['raw']['minor'])->toBe('10000');
});
test('availability settings history cannot be rewritten or deleted and downgrade refuses before DDL', function () {
    foreach (['update', 'delete'] as $operation) {
        expect(fn () => DB::transaction(function () use ($operation) {
            $q = DB::table('cash_availability_settings')->where('id', $this->settings->id);

            return $operation === 'update' ? $q->update(['reason' => 'Forbidden rewrite']) : $q->delete();
        }))->toThrow(QueryException::class);
    }
    $migration = require database_path('migrations/2026_10_18_120000_create_cash_availability_settings.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('cash_availability_settings', 1);
    $this->assertDatabaseCount('journal_entries', 1);
});
