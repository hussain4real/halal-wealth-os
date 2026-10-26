<?php

namespace Tests\Feature;

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\BalanceSnapshot;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\JournalPosted;
use App\Domain\Ledger\PostingService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Real commits, independent PostgreSQL connections, no wrapping test transaction.
uses(TestCase::class);
beforeEach(function () {
    RefreshDatabaseState::$migrated = false;
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});
afterEach(function () {
    try {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    } finally {
        RefreshDatabaseState::$migrated = false;
    }
});

/** @return array<string, string> */
function concurrencyInput(Account $account): array
{
    return ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '17.35', 'effective_date' => now()->toDateString(), 'description' => 'Synthetic concurrent movement'];
}

/**
 * The parent holds the actual DB advisory lock until BOTH independent backends
 * are observed waiting on it. Serial execution cannot satisfy this barrier.
 *
 * @param  list<array{operation: string, key: string, account?: string, original?: string, input?: array<string, string>}>  $commands
 * @return list<array<string, mixed>>
 */
function concurrentPostingRace(int $ownerId, array $commands): array
{
    DB::disconnect();
    $children = [];
    foreach ($commands as $command) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Required UNIX process barrier unavailable');
        }
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new \RuntimeException('Required fork failed');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            try {
                DB::purge();
                $backend = DB::selectOne('select pg_backend_pid() as pid')->pid;
                fwrite($pair[1], json_encode(['backend' => $backend], JSON_THROW_ON_ERROR)."\n");
                if (trim((string) fgets($pair[1])) !== 'go') {
                    throw new \RuntimeException('Barrier not released');
                }
                $owner = User::query()->findOrFail($ownerId);
                $service = app(PostingService::class);
                $journal = match ($command['operation']) {
                    'opening' => $service->postOpening($owner, $command['account'], $command['key']),
                    'reverse' => $service->reverse($owner, $command['original'], $command['key'], 'Synthetic race reversal'),
                    default => $service->record($owner, $command['key'], $command['input']),
                };
                $result = ['status' => 'success', 'id' => $journal->id, 'reference' => $journal->reference, 'backend' => $backend];
            } catch (ValidationException $error) {
                $result = ['status' => 'validation', 'errors' => $error->errors()];
            } catch (\Throwable $error) {
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

test('simultaneous independent PostgreSQL requests settle one key once and return stable receipts', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic race', AccountType::Bank, Currency::QAR, null);
    $input = concurrencyInput($account);
    $commands = array_fill(0, 2, ['operation' => 'record', 'key' => 'concurrent-same-key', 'input' => $input]);
    $results = concurrentPostingRace($owner->id, $commands);
    expect(array_column($results, 'status'))->toBe(['success', 'success'])->and($results[0]['id'])->toBe($results[1]['id'])->and($results[0]['reference'])->toBe($results[1]['reference']);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('postings', 2);
    expect(app(AccountBalanceReader::class)->forAccount($account)->money?->minor)->toBe('1735');
});

test('simultaneous changed payload with one key produces one success and one conflict', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic conflict', AccountType::Cash, Currency::QAR, null);
    $a = concurrencyInput($account);
    $b = $a;
    $b['amount'] = '19.10';
    $results = concurrentPostingRace($owner->id, [['operation' => 'record', 'key' => 'concurrent-conflict', 'input' => $a], ['operation' => 'record', 'key' => 'concurrent-conflict', 'input' => $b]]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('postings', 2);
});

test('simultaneous different keys cannot consume one immutable opening twice', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic opening race', AccountType::Bank, Currency::QAR, Money::fromMinor(Currency::QAR, '7350'));
    $results = concurrentPostingRace($owner->id, [['operation' => 'opening', 'key' => 'opening-race-0001', 'account' => (string) $account->id], ['operation' => 'opening', 'key' => 'opening-race-0002', 'account' => (string) $account->id]]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('opening_balance_postings', 1);
    $this->assertDatabaseCount('journal_entries', 1);
});

test('simultaneous reversal requests with different keys cannot reverse the original twice', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic reversal race', AccountType::Bank, Currency::QAR, null);
    $original = app(PostingService::class)->record($owner, 'reversal-original', concurrencyInput($account));
    $results = concurrentPostingRace($owner->id, [['operation' => 'reverse', 'key' => 'reversal-race-001', 'original' => (string) $original->id], ['operation' => 'reverse', 'key' => 'reversal-race-002', 'original' => (string) $original->id]]);
    $states = array_column($results, 'status');
    sort($states);
    expect($states)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('journal_entries', 2);
    expect($original->fresh()->status)->toBe('reversed')->and(app(AccountBalanceReader::class)->forAccount($account)->money?->minor)->toBe('0');
});

test('events wait for the real outer commit and are discarded with an outer rollback', function () {
    Event::fake([JournalPosted::class]);
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic events', AccountType::Bank, Currency::QAR, null);
    $service = app(PostingService::class);
    DB::transaction(function () use ($owner, $account, $service): void {
        $service->record($owner, 'outer-commit-001', concurrencyInput($account));
        Event::assertNotDispatched(JournalPosted::class);
    });
    Event::assertDispatchedTimes(JournalPosted::class, 1);
    expect(fn () => DB::transaction(function () use ($owner, $account, $service): void {
        $service->record($owner, 'outer-rollback-1', concurrencyInput($account));
        throw new \RuntimeException('Outer rollback');
    }))->toThrow(\RuntimeException::class);
    Event::assertDispatchedTimes(JournalPosted::class, 1);
    $this->assertDatabaseCount('journal_entries', 1);
    expect(app(AccountBalanceReader::class)->forAccount($account)->money?->minor)->toBe('1735');
});

test('posting waits on a separately connected archive and rechecks the committed account state', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic archive race', AccountType::Bank, Currency::QAR, null);
    $input = concurrencyInput($account);
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new \RuntimeException('Required process barrier unavailable');
    }
    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new \RuntimeException('Required process fork failed');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        DB::purge();
        fwrite($pair[1], (string) DB::selectOne('select pg_backend_pid() as pid')->pid."\n");
        fgets($pair[1]);
        try {
            app(PostingService::class)->record(User::query()->findOrFail($owner->id), 'archive-race-001', $input);
            $outcome = 'incorrect-success';
        } catch (ValidationException) {
            $outcome = 'archived-rejected';
        }
        fwrite($pair[1], $outcome."\n");
        fclose($pair[1]);
        DB::disconnect();
        exit(0);
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 15);
    $backend = (int) trim((string) fgets($pair[0]));
    DB::beginTransaction();
    $waiting = false;
    try {
        DB::table('accounts')->where('id', $account->id)->lockForUpdate()->first();
        DB::table('accounts')->where('id', $account->id)->update(['archived_at' => now()]);
        fwrite($pair[0], "go\n");
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->where('pid', $backend)->where('wait_event_type', 'Lock')->whereIn('wait_event', ['transactionid', 'tuple'])->exists();
            if ($waiting) {
                break;
            }usleep(10000);
        } while (microtime(true) < $deadline);
        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
    $outcome = trim((string) fgets($pair[0]));
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);
    expect($waiting)->toBeTrue()->and($outcome)->toBe('archived-rejected')->and(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('postings', 0);
    expect($account->fresh()->archived_at)->not->toBeNull();
});

test('a complete pending header contributes zero and cached pending keys never report a posted success', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic pending', AccountType::Bank, Currency::QAR, null);
    DB::table('journal_entries')->insert(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => 'pending-key-0001', 'payload_hash' => str_repeat('0', 64), 'request_payload' => '{}', 'kind' => 'adjustment', 'status' => 'pending', 'effective_date' => now()->toDateString(), 'description' => 'Unposted header']);
    expect(app(AccountBalanceReader::class)->forAccount($account)->money?->minor)->toBe('0');
    expect(fn () => app(PostingService::class)->record($owner, 'pending-key-0001', concurrencyInput($account)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('postings', 0);
});

test('listener failure after commit leaves the durable journal safe to replay without duplicate posting', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic downstream', AccountType::Cash, Currency::QAR, null);
    $input = concurrencyInput($account);
    $calls = 0;
    Event::listen(JournalPosted::class, function () use (&$calls): never {
        $calls++;
        throw new \RuntimeException('Synthetic downstream failure');
    });
    try {
        expect(fn () => app(PostingService::class)->record($owner, 'listener-retry-01', $input))->toThrow(\RuntimeException::class);
        $durable = JournalEntry::query()->sole();
        $retry = app(PostingService::class)->record($owner, 'listener-retry-01', $input);
        expect($retry->id)->toBe($durable->id)->and($calls)->toBe(1);
        $this->assertDatabaseCount('postings', 2);
    } finally {
        Event::forget(JournalPosted::class);
    }
});

test('PostgreSQL rejects unbalanced state transitions and pending legs at actual commit', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic DB guard', AccountType::Bank, Currency::QAR, null);
    foreach (['posted', 'pending'] as $state) {
        expect(fn () => DB::transaction(function () use ($owner, $account, $state): void {
            $id = DB::table('journal_entries')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => 'invalid-db-'.$state, 'payload_hash' => str_repeat('0', 64), 'request_payload' => '{}', 'kind' => 'adjustment', 'status' => 'pending', 'effective_date' => now()->toDateString(), 'description' => 'Invalid DB journal']);
            DB::table('postings')->insert(['journal_id' => $id, 'owner_id' => $owner->id, 'account_id' => $account->id, 'currency_code' => 'QAR', 'amount_minor' => '1']);
            if ($state === 'posted') {
                DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
            }
        }))->toThrow(\PDOException::class, $state === 'posted' ? 'Posted journals must balance per currency' : 'Pending journals cannot retain financial legs');
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('postings', 0);
    }
});

test('account responses retain one financial snapshot across a separate opening or reversal commit', function (string $surface, string $operation) {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic snapshot race', AccountType::Bank, Currency::QAR, Money::fromMinor(Currency::QAR, '12550'));
    $original = $operation === 'reverse' ? app(PostingService::class)->postOpening($owner, (string) $account->id, 'snapshot-original') : null;
    $reader = app(AccountBalanceReader::class);
    $readerBackend = DB::selectOne('select pg_backend_pid() as pid')->pid;
    $connection = DB::getDefaultConnection();
    config(['database.connections.snapshot_writer' => config('database.connections.'.$connection)]);
    $committed = null;
    $commit = function () use ($connection, $readerBackend, $owner, $account, $original, &$committed): void {
        DB::setDefaultConnection('snapshot_writer');
        try {
            expect(DB::selectOne('select pg_backend_pid() as pid')->pid)->not->toBe($readerBackend);
            $committed = $original === null
                ? app(PostingService::class)->postOpening($owner, (string) $account->id, 'snapshot-opening')
                : app(PostingService::class)->reverse($owner, (string) $original->id, 'snapshot-reversal', 'Synthetic snapshot reversal');
            expect(DB::transactionLevel())->toBe(0);
        } finally {
            DB::disconnect('snapshot_writer');
            DB::setDefaultConnection($connection);
        }
    };
    app()->instance(AccountBalanceReader::class, new class($reader, $commit, $account->id) implements AccountBalanceReader
    {
        private bool $committed = false;

        public function __construct(private AccountBalanceReader $reader, private \Closure $commit, private int $accountId) {}

        public function forAccount(Account $account): BalanceSnapshot
        {
            $snapshot = $this->reader->forAccount($account);
            if ($account->id === $this->accountId && ! $this->committed) {
                $this->committed = true;
                ($this->commit)();
            }

            return $snapshot;
        }
    });
    $url = $surface === 'show' ? '/accounts/'.$account->id : '/accounts';
    $path = $surface === 'show' ? 'account.' : 'accounts.0.';
    $this->actingAs($owner)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where($path.'opening_request.status', $original === null ? 'awaiting_posting' : 'posted')
        ->where($path.'opening_request.journal_id', $original === null ? null : (string) $original->id)
        ->where($path.'balance', $original === null ? null : Money::fromMinor(Currency::QAR, '12550')->jsonSerialize())
        ->where($path.'balance_reason', $original === null ? 'opening_pending' : null));
    expect($committed)->toBeInstanceOf(JournalEntry::class);
    app()->instance(AccountBalanceReader::class, $reader);
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where($path.'opening_request.status', $original === null ? 'posted' : 'reversed')
        ->where($path.'opening_request.journal_id', (string) ($original?->id ?? $committed->id))
        ->where($path.'balance.minor', $original === null ? '12550' : '0')
        ->where($path.'balance_reason', null));
})->with([['show', 'opening'], ['index', 'opening'], ['show', 'reverse'], ['index', 'reverse']]);
