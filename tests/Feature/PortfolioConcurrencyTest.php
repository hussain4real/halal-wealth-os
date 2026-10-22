<?php

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\PostingService;
use App\Domain\Portfolio\PortfolioService;
use App\Domain\Reporting\NetWorthSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
function concurrentPortfolioRace(int $ownerId, array $commands): array
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
                $service = app(PortfolioService::class);
                $journal = match ($command['operation']) {
                    'confirm' => $service->confirm($owner, $command['portfolio'], $command['key'], $command['preview'], '1'),
                    'opening' => $service->opening($owner, $command['portfolio'], $command['key'], $command['input']),
                    'save' => app(NetWorthSnapshot::class)->save($owner, $command['key'], Currency::QAR, now('UTC')->format('Y-m-d\\TH:i:s\\Z')),
                    default => throw new RuntimeException('Unsupported synthetic race command'),
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
test('mode preview cannot take an account row before its owner lock', function () {
    $owner = User::factory()->create();
    $account = app(CreateAccount::class)->create($owner, 'Synthetic lock-order investment', AccountType::Investment, Currency::QAR, null);
    $p = app(PortfolioService::class)->link($owner, 'lock-order-link-identity', ['account_id' => (string) $account->id, 'platform_label' => 'Synthetic platform', 'source' => 'Synthetic statement']);
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Required UNIX barrier missing');
    }
    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('Required fork failed');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        DB::purge();
        try {
            fwrite($pair[1], json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid])."\n");
            if (trim((string) fgets($pair[1])) !== 'go') {
                throw new RuntimeException('Barrier not released');
            }
            app(PortfolioService::class)->preview(User::findOrFail($owner->id), (string) $p->id, ['mode' => 'detailed', 'platform_label' => 'Synthetic platform', 'source' => 'Synthetic statement', 'reason' => 'Synthetic review']);
            fwrite($pair[1], json_encode(['status' => 'success'])."\n");
        } catch (Throwable $e) {
            fwrite($pair[1], json_encode(['status' => 'error', 'class' => $e::class, 'message' => $e->getMessage()])."\n");
        }
        DB::disconnect();
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 15);
    $backend = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR)['pid'];
    $accountFree = false;
    $waiting = false;
    DB::beginTransaction();
    try {
        LedgerInput::lock($owner);
        fwrite($pair[0], "go\n");
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->where('pid', $backend)->where('wait_event', 'advisory')->exists();
            if ($waiting) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        try {
            DB::select('SELECT id FROM accounts WHERE id=? FOR UPDATE NOWAIT', [$account->id]);
            $accountFree = true;
        } catch (QueryException $e) {
            if ($e->getCode() !== '55P03') {
                throw $e;
            }
        }
    } finally {
        DB::rollBack();
    }
    $result = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);
    expect($waiting)->toBeTrue()->and($result['status'])->toBe('success')->and($accountFree)->toBeTrue('Preview must wait for the owner before locking the account');
});

test('same key concurrent confirmations return one committed mode identity', function () {
    $owner = User::factory()->create();
    $p = P::link($owner, P::account($owner));
    $preview = app(PortfolioService::class)->preview($owner, (string) $p->id, P::modeInput());
    $cmd = ['operation' => 'confirm', 'portfolio' => (string) $p->id, 'key' => 'concurrent-same-confirm', 'preview' => (string) $preview->id];
    $r = concurrentPortfolioRace($owner->id, [$cmd, $cmd]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('portfolio_modes', 1);
});
test('two different previews of one baseline cannot both commit a mode fork', function () {
    $owner = User::factory()->create();
    $p = P::link($owner, P::account($owner));
    $a = app(PortfolioService::class)->preview($owner, (string) $p->id, P::modeInput());
    $b = app(PortfolioService::class)->preview($owner, (string) $p->id, P::modeInput(['mode' => 'aggregate', 'aggregate_value' => '17', 'aggregate_as_of' => now('UTC')->toDateString()]));
    $r = concurrentPortfolioRace($owner->id, [
        ['operation' => 'confirm', 'portfolio' => (string) $p->id, 'key' => 'concurrent-first-mode', 'preview' => (string) $a->id],
        ['operation' => 'confirm', 'portfolio' => (string) $p->id, 'key' => 'concurrent-other-mode', 'preview' => (string) $b->id],
    ]);
    $counts = array_count_values(array_column($r, 'status'));
    expect($counts['success'])->toBe(1)->and($counts['validation'])->toBe(1);
    $this->assertDatabaseCount('portfolio_modes', 1);
});
test('concurrent complete opening corrections preserve one linear book and stale the loser', function () {
    $owner = User::factory()->create();
    $p = P::link($owner, P::account($owner));
    $asset = P::asset($owner);
    $book = P::opening($owner, $p, [P::row($asset)]);
    $input = ['prior_id' => (string) $book->id, 'as_of' => $book->as_of, 'source' => 'Synthetic race statement', 'reason' => 'Synthetic correction', 'rows' => [P::row($asset, ['quantity' => '2'])]];
    $r = concurrentPortfolioRace($owner->id, [
        ['operation' => 'opening', 'portfolio' => (string) $p->id, 'key' => 'concurrent-first-book', 'input' => $input],
        ['operation' => 'opening', 'portfolio' => (string) $p->id, 'key' => 'concurrent-other-book', 'input' => $input],
    ]);
    $counts = array_count_values(array_column($r, 'status'));
    expect($counts['success'])->toBe(1)->and($counts['validation'])->toBe(1);
    $this->assertDatabaseCount('portfolio_openings', 2);
    $this->assertDatabaseCount('portfolio_opening_rows', 2);
});
test('saved reporting and mode confirmation serialize to one coherent selected contribution', function () {
    $owner = User::factory()->create();
    $p = P::link($owner, P::account($owner));
    P::mode($owner, $p);
    $preview = app(PortfolioService::class)->preview($owner, (string) $p->id, P::modeInput(['mode' => 'aggregate', 'aggregate_value' => '17', 'aggregate_as_of' => now('UTC')->toDateString()]));
    $r = concurrentPortfolioRace($owner->id, [
        ['operation' => 'confirm', 'portfolio' => (string) $p->id, 'key' => 'concurrent-report-mode', 'preview' => (string) $preview->id],
        ['operation' => 'save', 'key' => 'concurrent-report-save'],
    ]);
    expect(array_column($r, 'status'))->toBe(['success', 'success']);
    $e = json_decode(DB::table('valuation_reports')->sole()->evidence, true, flags: JSON_THROW_ON_ERROR);
    $mode = $e['components'][0]['portfolio']['mode'];
    expect($e['total']['minor'])->toBe($mode === 'aggregate' ? '1700' : '0');
});
test('actual reporting repeatable read cannot mix an old ledger with a newly committed opening', function () {
    $owner = User::factory()->create();
    $p = P::link($owner, P::account($owner));
    $asset = P::asset($owner);
    $book = P::opening($owner, $p, [P::row($asset, ['quantity' => '1', 'unit_price' => '2'])]);
    P::mode($owner, $p);
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Required UNIX barrier unavailable');
    }
    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('Required fork failed');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        DB::purge();
        stream_set_timeout($pair[1], 15);
        try {
            $paused = false;
            DB::listen(function ($query) use ($pair, &$paused) {
                if (! $paused && str_contains($query->sql, 'from "portfolio_accounts"')) {
                    $paused = true;
                    fwrite($pair[1], json_encode(['backend' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, 'isolation' => DB::selectOne('SHOW transaction_isolation')->transaction_isolation])."\n");
                    if (trim((string) fgets($pair[1])) !== 'go') {
                        throw new RuntimeException('Reader barrier missing');
                    }
                }
            });
            $e = app(NetWorthSnapshot::class)->capture(User::findOrFail($owner->id), Currency::QAR, now('UTC')->format('Y-m-d\\TH:i:s\\Z'));
            fwrite($pair[1], json_encode(['status' => 'success', 'minor' => $e['total']['minor']])."\n");
        } catch (Throwable $e) {
            fwrite($pair[1], json_encode(['status' => 'error', 'message' => $e->getMessage()])."\n");
        }
        DB::disconnect();
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 15);
    $ready = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
    try {
        P::opening($owner, $p, [P::row($asset, ['quantity' => '3', 'unit_price' => '2'])], ['prior_id' => (string) $book->id]);
    } finally {
        fwrite($pair[0], "go\n");
    }
    $result = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);
    expect($ready['isolation'])->toBe('repeatable read')->and($result)->toBe(['status' => 'success', 'minor' => '200']);
    expect(app(NetWorthSnapshot::class)->capture($owner, Currency::QAR, now('UTC')->format('Y-m-d\\TH:i:s\\Z'))['total']['minor'])->toBe('600');
});

test('incomplete raw opening book rolls back at actual PostgreSQL commit', function () {
    $owner = User::factory()->create();
    $p = P::link($owner, P::account($owner));
    expect(fn () => DB::transaction(fn () => DB::table('portfolio_openings')->insert(['owner_id' => $owner->id, 'portfolio_account_id' => $p->id, 'currency' => 'QAR', 'reference' => (string) Str::ulid(), 'version' => 1, 'as_of' => now('UTC')->toDateString(), 'row_count' => 1, 'source' => 'Synthetic incomplete statement', 'reason' => 'Synthetic atomicity probe'])))->toThrow(PDOException::class, 'Opening statement rows must commit completely');
    $this->assertDatabaseCount('portfolio_openings', 0);
    $this->assertDatabaseCount('portfolio_requests', 1);
});
test('empty portfolio migration down and upgrade preserve existing ledger accounts journals and report', function () {
    $imports = require database_path('migrations/2026_10_22_100000_create_statement_imports.php');
    $imports->down();
    $owner = User::factory()->create();
    $a = P::account($owner);
    app(PostingService::class)->record($owner, 'portfolio-upgrade-cash', ['kind' => 'income', 'account_id' => (string) $a->id, 'amount' => '13.57', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic retained cash']);
    $report = app(NetWorthSnapshot::class)->save($owner, 'portfolio-upgrade-report', Currency::QAR, now('UTC')->format('Y-m-d\\TH:i:s\\Z'));
    $decisions = require database_path('migrations/2026_10_21_064500_create_decision_records.php');
    $decisions->down();
    $purification = require database_path('migrations/2026_10_20_073000_create_purification_records.php');
    $purification->down();
    $assessment = require database_path('migrations/2026_10_19_110000_create_shariah_assessment_evidence.php');
    $assessment->down();
    $valuation = require database_path('migrations/2026_10_19_000001_create_investment_valuation_evidence.php');
    $valuation->down();
    $investment = require database_path('migrations/2026_10_18_171000_create_investment_event_subledger.php');
    $investment->down();
    expect(Schema::hasTable('investment_events'))->toBeFalse();
    $migration = require database_path('migrations/2026_10_18_150000_create_portfolio_opening_evidence.php');
    $migration->down();
    $migration->up();
    $investment->up();
    $valuation->up();
    $assessment->up();
    $purification->up();
    $decisions->up();
    $imports->up();
    expect(app(NetWorthSnapshot::class)->capture($owner, Currency::QAR, now('UTC')->format('Y-m-d\\TH:i:s\\Z'))['total']['minor'])->toBe('1357')->and(DB::table('valuation_reports')->where('id', $report->id)->value('evidence'))->toBe($report->evidence);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('portfolio_accounts', 0);
});
