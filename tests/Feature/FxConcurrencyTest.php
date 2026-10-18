<?php

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\FxService;
use App\Domain\Ledger\JournalPosted;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\ReconciliationService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FxFixtures as Fx;
use Tests\TestCase;

// TestCase verifies the separately inspected disposable PostgreSQL configuration
// before these destructive fresh migrations. These tests need REAL commits.
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

/**
 * The parent holds the actual DB advisory lock until BOTH independent backends
 * are observed waiting on it. Serial execution cannot satisfy this barrier.
 *
 * @param  list<array{operation: string, key: string, account?: string, original?: string, input?: array<string, string>}>  $commands
 * @return list<array<string, mixed>>
 */
function concurrentFxRace(int $ownerId, array $commands): array
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
                $service = app(FxService::class);
                $journal = match ($command['operation']) {
                    'reverse' => $service->reverse($owner, $command['original'], $command['key'], 'Synthetic FX reversal'),
                    'adjust' => app(ReconciliationService::class)->adjust($owner, $command['original'], $command['key'], 'equity', 'Synthetic statement correction'),
                    default => $service->record($owner, $command['key'], $command['input']),
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

test('independent PostgreSQL FX requests recover one durable group for the same key', function () {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $input = Fx::input($source, $destination);
    $results = concurrentFxRace($owner->id, array_fill(0, 2, ['operation' => 'record', 'key' => 'fx-real-race-001', 'input' => $input]));
    expect(array_column($results, 'status'))->toBe(['success', 'success'])->and($results[0]['id'])->toBe($results[1]['id'])->and($results[0]['reference'])->toBe($results[1]['reference']);
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('fx_observations', 1);
    $this->assertDatabaseCount('journal_entries', 3);
    $this->assertDatabaseCount('postings', 6);
});

test('independent FX changed payload conflicts instead of silently recovering different cash', function () {
    $owner = User::factory()->create();
    $input = Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN));
    $other = [...$input, 'source_amount' => '74.25'];
    $results = concurrentFxRace($owner->id, [['operation' => 'record', 'key' => 'fx-real-conflict', 'input' => $input], ['operation' => 'record', 'key' => 'fx-real-conflict', 'input' => $other]]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('journal_entries', 3);
});

test('concurrent complete reversals cannot reverse either FX principal or its fee twice', function () {
    $owner = User::factory()->create();
    $operation = app(FxService::class)->record($owner, 'fx-reversal-base', Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN)));
    $results = concurrentFxRace($owner->id, [['operation' => 'reverse', 'key' => 'fx-reverse-race-1', 'original' => (string) $operation->id], ['operation' => 'reverse', 'key' => 'fx-reverse-race-2', 'original' => (string) $operation->id]]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('fx_reversals', 1);
    $this->assertDatabaseCount('journal_entries', 6);
    expect(DB::table('fx_operations')->value('status'))->toBe('reversed');
    expect(app(FinancialSnapshot::class)->capture($owner, Currency::QAR, now('UTC')->format('Y-m-d\TH:i:s\Z'))['components'][0]['native']['minor'])->toBe('0');
});

test('concurrent reconciliation adjustments preserve one correction and one linked matched result', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $session = app(ReconciliationService::class)->record($owner, 'rec-real-race-base', (string) $account->id, '17.35', now('UTC')->format('Y-m-d\TH:i:s\Z'));
    $results = concurrentFxRace($owner->id, [['operation' => 'adjust', 'key' => 'rec-race-00001', 'original' => (string) $session->id], ['operation' => 'adjust', 'key' => 'rec-race-00002', 'original' => (string) $session->id]]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('reconciliation_adjustments', 1);
    $this->assertDatabaseCount('reconciliation_sessions', 2);
    $this->assertDatabaseCount('journal_entries', 1);
    expect(DB::table('reconciliation_sessions')->where('id', '!=', $session->id)->value('status'))->toBe('matched');
});

test('deferred database guard rejects a standalone constituent reversal at the real outer commit', function () {
    $owner = User::factory()->create();
    $operation = app(FxService::class)->record($owner, 'fx-partial-base', Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN)));
    $journal = DB::table('fx_operation_journals')->where('operation_id', $operation->id)->where('role', 'source')->value('journal_id');
    expect(fn () => app(PostingService::class)->reverseFxMember($owner, (string) $journal, 'fx-partial-denied', 'Synthetic incomplete reversal', $operation->id))->toThrow(PDOException::class, 'FX constituents cannot be reversed separately');
    $this->assertDatabaseCount('journal_entries', 3);
    expect(DB::table('journal_entries')->where('status', 'reversed')->count())->toBe(0);
    expect(DB::table('fx_operations')->value('status'))->toBe('posted');
});

test('deferred database guard rejects an incomplete pending FX root and retains no successful key', function () {
    $owner = User::factory()->create();
    $operation = app(FxService::class)->record($owner, 'fx-complete-base', Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN)));
    $copy = (array) $operation;
    unset($copy['id']);
    $copy['idempotency_key'] = 'fx-incomplete-new';
    $copy['reference'] = (string) Str::ulid();
    $copy['status'] = 'pending';
    expect(fn () => DB::transaction(fn () => DB::table('fx_operations')->insert($copy)))->toThrow(PDOException::class, 'FX operation must commit all currency and fee journals together');
    $this->assertDatabaseCount('fx_operations', 1);
});

test('FX events wait for the outer commit and all events disappear after an outer rollback', function () {
    Event::fake([JournalPosted::class]);
    $owner = User::factory()->create();
    $input = Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN));
    DB::transaction(function () use ($owner, $input) {
        app(FxService::class)->record($owner, 'fx-outer-commit', $input);
        Event::assertNotDispatched(JournalPosted::class);
    });
    Event::assertDispatchedTimes(JournalPosted::class, 3);
    expect(fn () => DB::transaction(function () use ($owner, $input) {
        app(FxService::class)->record($owner, 'fx-outer-rollback', $input);
        throw new RuntimeException('Synthetic outer rollback');
    }))->toThrow(RuntimeException::class);
    Event::assertDispatchedTimes(JournalPosted::class, 3);
    $this->assertDatabaseCount('fx_operations', 1);
});

test('FX committed listener failure recovers the durable root without reposting or redelivering missing events', function () {
    $owner = User::factory()->create();
    $input = Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN));
    $attempts = 0;
    Event::listen(JournalPosted::class, function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('Synthetic listener unavailable after commit');
    });
    expect(fn () => app(FxService::class)->record($owner, 'fx-listener-recovery', $input))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('journal_entries', 3);
    $original = DB::table('fx_operations')->first();
    $recovered = app(FxService::class)->record($owner, 'fx-listener-recovery', $input);
    expect($recovered->id)->toBe($original->id)->and($recovered->reference)->toBe($original->reference)->and($attempts)->toBe(1);
    $this->assertDatabaseCount('journal_entries', 3);
});

test('one SQL snapshot keeps both cash legs and reporting quotes coherent across an interleaved commit', function () {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $oldRate = app(FxObservationService::class)->record($owner, 'snapshot-old-rate', Fx::quote());
    $connection = DB::getDefaultConnection();
    config(['database.connections.fx_snapshot_writer' => config('database.connections.'.$connection)]);
    $triggered = false;
    DB::listen(function (QueryExecuted $query) use (&$triggered, $connection, $owner, $source, $destination, $oldRate) {
        if ($triggered || ! str_contains($query->sql, 'WITH parameters AS')) {
            return;
        }
        $triggered = true;
        DB::setDefaultConnection('fx_snapshot_writer');
        try {
            app(FxService::class)->record($owner, 'snapshot-interleave-fx', Fx::input($source, $destination));
            app(FxObservationService::class)->record($owner, 'snapshot-new-rate', [...Fx::quote(), 'value' => '0.003', 'supersedes_id' => (string) $oldRate->id, 'correction_reason' => 'Synthetic quote correction']);
        } finally {
            DB::setDefaultConnection($connection);
        }
    });
    $cutoff = now('UTC')->format('Y-m-d\TH:i:s\Z');
    $before = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, $cutoff);
    $after = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, $cutoff);
    expect($triggered)->toBeTrue()->and(array_column(array_column($before['components'], 'native'), 'minor'))->toBe(['0', '0'])
        ->and($before['components'][1]['rate']['id'])->toBe((string) $oldRate->id)->and($before['components'][0]['journals'])->toBe([])->and($before['components'][1]['journals'])->toBe([])
        ->and(array_column(array_column($after['components'], 'native'), 'minor'))->toBe(['-7575', '2966625'])->and($after['components'][1]['rate']['value'])->toBe('0.003000000000');
    DB::purge('fx_snapshot_writer');
});

test('failed replacement rolls back its complete reversal and retains the original posted operation', function () {
    $owner = User::factory()->create();
    $input = Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN));
    $original = app(FxService::class)->record($owner, 'fx-correction-base', $input);
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed) {
        if ($armed && str_contains($query->sql, 'insert into "fx_operations"')) {
            $armed = false;
            throw new RuntimeException('Synthetic replacement failure');
        }
    });
    expect(fn () => app(FxService::class)->record($owner, 'fx-correction-fail', [...$input, 'source_amount' => '74.25'], (string) $original->id))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('fx_reversals', 0);
    $this->assertDatabaseCount('journal_entries', 3);
    expect(DB::table('fx_operations')->value('status'))->toBe('posted');
    expect(app(FxService::class)->record($owner, 'fx-correction-fail', [...$input, 'source_amount' => '74.25'], (string) $original->id)->status)->toBe('posted');
    $this->assertDatabaseCount('fx_operations', 2);
    $this->assertDatabaseCount('journal_entries', 9);
});

test('failed reconciliation result rolls back its posting and a retry produces one linked zero difference', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $session = app(ReconciliationService::class)->record($owner, 'rec-failure-base', (string) $account->id, '17.35', now('UTC')->format('Y-m-d\TH:i:s\Z'));
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed) {
        if ($armed && str_contains($query->sql, 'insert into "reconciliation_sessions"')) {
            $armed = false;
            throw new RuntimeException('Synthetic result-save failure');
        }
    });
    expect(fn () => app(ReconciliationService::class)->adjust($owner, (string) $session->id, 'rec-failed-adjustment', 'equity', 'Synthetic correction'))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('reconciliation_sessions', 1);
    $this->assertDatabaseCount('reconciliation_adjustments', 0);
    expect(app(ReconciliationService::class)->adjust($owner, (string) $session->id, 'rec-failed-adjustment', 'equity', 'Synthetic correction')->status)->toBe('matched');
});

test('empty FX migration rolls back and remigrates while populated evidence refuses downgrade before DDL', function () {
    // Target this schema explicitly; later planning migrations follow it now.
    $migration = require database_path('migrations/2026_10_18_060000_create_manual_fx_and_reconciliation.php');
    $migration->down();
    expect(Schema::hasTable('fx_operations'))->toBeFalse();
    $migration->up();
    $owner = User::factory()->create();
    app(FxService::class)->record($owner, 'fx-downgrade-base', Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN)));
    $migration = require database_path('migrations/2026_10_18_060000_create_manual_fx_and_reconciliation.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'retained clearing accounts');
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('journal_entries', 3);
});

test('FX schema upgrade preserves prior journals and unconsumed opening intents without automatic posting', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic upgrade opening', AccountType::Bank, Currency::QAR, Money::fromDecimal(Currency::QAR, '125.50'));
    $journal = app(PostingService::class)->record($owner, 'upgrade-existing-journal', ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '17.35', 'effective_date' => now()->toDateString(), 'description' => 'Synthetic pre-upgrade movement']);
    $before = ['account' => (array) DB::table('accounts')->where('id', $account->id)->first(), 'intent' => (array) DB::table('opening_balance_intents')->where('account_id', $account->id)->first(), 'journal' => (array) DB::table('journal_entries')->where('id', $journal->id)->first(), 'postings' => DB::table('postings')->orderBy('id')->get()->toArray()];
    $migration = require database_path('migrations/2026_10_18_060000_create_manual_fx_and_reconciliation.php');
    $migration->down();
    $migration->up();
    expect((array) DB::table('accounts')->where('id', $account->id)->first())->toBe($before['account'])
        ->and((array) DB::table('opening_balance_intents')->where('account_id', $account->id)->first())->toBe($before['intent'])
        ->and((array) DB::table('journal_entries')->where('id', $journal->id)->first())->toBe($before['journal'])
        ->and(DB::table('postings')->orderBy('id')->get()->toArray())->toEqual($before['postings']);
    $this->assertDatabaseCount('opening_balance_postings', 0);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('fx_operations', 0);
});

test('manual observation evidence without FX journals also blocks a destructive downgrade before DDL', function () {
    $owner = User::factory()->create();
    app(FxObservationService::class)->record($owner, 'retained-observation', Fx::quote());
    $migration = require database_path('migrations/2026_10_18_060000_create_manual_fx_and_reconciliation.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'retained financial evidence');
    $this->assertDatabaseCount('fx_observations', 1);
    expect(Schema::hasTable('reconciliation_sessions'))->toBeTrue();
});

test('a complete FX reversal cannot commit currency members on different business dates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $owner = User::factory()->create();
    $input = [...Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN)), 'effective_date' => '2026-10-15', 'observed_at' => '2026-10-15T00:00:00Z'];
    $original = app(FxService::class)->record($owner, 'cross-date-original', $input);
    expect(fn () => DB::transaction(function () use ($owner, $original) {
        DB::table('fx_reversals')->insert(['owner_id' => $owner->id, 'operation_id' => $original->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => 'cross-date-reversal', 'payload_hash' => str_repeat('0', 64), 'reason' => 'Synthetic inconsistent period']);
        foreach (DB::table('fx_operation_journals')->where('operation_id', $original->id)->get() as $member) {
            $journal = app(PostingService::class)->reverseFxMember($owner, (string) $member->journal_id, 'cross-date-'.$member->role, 'Synthetic inconsistent period', $original->id, $member->role === 'destination' ? '2026-10-17' : '2026-10-15');
            DB::table('fx_operation_journals')->insert(['operation_id' => $original->id, 'owner_id' => $owner->id, 'journal_id' => $journal->id, 'role' => $member->role.'_reversal']);
        }
        DB::table('fx_operations')->where('id', $original->id)->update(['status' => 'reversed']);
    }))->toThrow(PDOException::class, 'one UTC business date');
    $this->assertDatabaseCount('fx_reversals', 0);
    $this->assertDatabaseCount('journal_entries', 3);
    expect(DB::table('fx_operations')->value('status'))->toBe('posted');
});

test('historical FX replacement commits its original-date cancellations and correct cutoff balances together', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $owner = User::factory()->create();
    $input = [...Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN)), 'effective_date' => '2026-10-15', 'observed_at' => '2026-10-15T00:00:00Z'];
    $original = app(FxService::class)->record($owner, 'committed-history-base', $input);
    app(FxService::class)->record($owner, 'committed-history-corrected', [...$input, 'effective_date' => '2026-10-17'], (string) $original->id);
    $before = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, '2026-10-16T12:00:00Z');
    $after = app(FinancialSnapshot::class)->capture($owner, Currency::QAR, '2026-10-17T12:00:00Z');
    expect(array_column(array_column($before['components'], 'native'), 'minor'))->toBe(['0', '0'])
        ->and(array_column(array_column($after['components'], 'native'), 'minor'))->toBe(['-7575', '2966625']);
    $this->assertDatabaseCount('journal_entries', 9);
    $this->assertDatabaseCount('fx_operations', 2);
});

test('a new FX replacement cannot commit uniformly dated but wrong-period original cancellations', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $owner = User::factory()->create();
    $input = [...Fx::input(Fx::account($owner), Fx::account($owner, Currency::NGN)), 'effective_date' => '2026-10-15', 'observed_at' => '2026-10-15T00:00:00Z'];
    $original = app(FxService::class)->record($owner, 'wrong-period-original', $input);
    app(FxService::class)->reverse($owner, (string) $original->id, 'wrong-period-standalone', 'Synthetic standalone reversal today');
    expect(fn () => DB::transaction(function () use ($owner, $original) {
        $copy = (array) $original;
        unset($copy['id']);
        $copy['reference'] = (string) Str::ulid();
        $copy['idempotency_key'] = 'wrong-period-replacement';
        $copy['status'] = 'pending';
        $copy['replaces_id'] = $original->id;
        $payload = json_decode($copy['request_payload'], true, flags: JSON_THROW_ON_ERROR);
        $payload['replaces_id'] = $original->id;
        $copy['request_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
        $copy['payload_hash'] = LedgerInput::hash($payload);
        $id = DB::table('fx_operations')->insertGetId($copy);
        foreach (DB::table('fx_operation_journals')->where('operation_id', $original->id)->whereIn('role', ['source', 'destination', 'fee'])->get() as $member) {
            $legs = DB::table('postings')->where('journal_id', $member->journal_id)->get()->map(fn ($leg) => ['account_id' => (string) $leg->account_id, 'minor' => (string) $leg->amount_minor])->all();
            $journal = app(PostingService::class)->postBalanced($owner, 'wrong-period-child-'.$member->role, $legs, 'Synthetic wrong-period replacement', $original->effective_date, ['fx_operation_id' => (string) $id], $member->role === 'fee' ? 'expense' : 'adjustment');
            DB::table('fx_operation_journals')->insert(['operation_id' => $id, 'owner_id' => $owner->id, 'journal_id' => $journal->id, 'role' => $member->role]);
        }
        DB::table('fx_operations')->where('id', $id)->update(['status' => 'posted']);
    }))->toThrow(PDOException::class, 'original business date');
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('journal_entries', 6);
    $this->assertDatabaseCount('fx_reversals', 1);
});
