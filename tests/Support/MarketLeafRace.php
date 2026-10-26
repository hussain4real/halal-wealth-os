<?php

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\MarketFixtures;

/** Actual independent PostgreSQL backends; child-only barriers add no production test hook. */
function expiredMarketLeafRace(): array
{
    DB::disconnect();
    $children = [];
    $httpReleased = false;
    $resultReleased = false;
    $winnerStarted = false;
    try {
        foreach (['old', 'winner'] as $role) {
            DB::disconnect();
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                throw new RuntimeException('Required process barrier unavailable.');
            }
            $pid = pcntl_fork();
            if ($pid < 0) {
                throw new RuntimeException('Required fork unavailable.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                stream_set_timeout($pair[1], 30);
                try {
                    DB::purge();
                    Http::swap(new Factory);
                    Http::preventStrayRequests();
                    $backend = DB::selectOne('select pg_backend_pid() as pid')->pid;
                    $afterWire = false;
                    $committedGate = false;
                    if ($role === 'old') {
                        Event::listen(TransactionCommitted::class, function ($event) use (&$afterWire, &$committedGate, $pair): void {
                            if ($afterWire && ! $committedGate && $event->connection->transactionLevel() === 0) {
                                $committedGate = true;
                                fwrite($pair[1], json_encode(['stage' => 'fetch_committed'])."\n");
                                if (trim((string) fgets($pair[1])) !== 'release_result') {
                                    throw new RuntimeException('Old result barrier not released.');
                                }
                            }
                        });
                    } else {
                        Date::setTestNow(new DateTimeImmutable('2026-10-23T12:00:21Z'));
                        fwrite($pair[1], json_encode(['stage' => 'ready', 'backend' => $backend])."\n");
                        if (trim((string) fgets($pair[1])) !== 'go') {
                            throw new RuntimeException('Winner barrier not released.');
                        }
                    }
                    Http::fake(function () use ($role, $pair, $backend, &$afterWire) {
                        if ($role === 'old') {
                            fwrite($pair[1], json_encode(['stage' => 'fetching', 'backend' => $backend])."\n");
                            if (trim((string) fgets($pair[1])) !== 'release_http') {
                                throw new RuntimeException('Old HTTP barrier not released.');
                            }
                            Date::setTestNow(new DateTimeImmutable('2026-10-23T12:00:22Z'));
                        }
                        $afterWire = true;

                        return Http::response(MarketFixtures::fxBody(value: $role === 'old' ? '3.64' : '3.65'));
                    });
                    $result = app(MarketRefresh::class)->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23');
                    $result['actual_fake_sends'] = count(Http::recorded());
                } catch (Throwable $error) {
                    $result = ['status' => 'error', 'message' => $error->getMessage()];
                }
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                fclose($pair[1]);
                DB::disconnect();
                exit(0);
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 30);
            $children[$role] = ['pid' => $pid, 'pipe' => $pair[0]];
            $ready = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
            expect($ready['stage'])->toBe($role === 'old' ? 'fetching' : 'ready');
            $children[$role]['backend'] = $ready['backend'];
        }
        expect($children['old']['backend'])->not->toBe($children['winner']['backend']);
        fwrite($children['winner']['pipe'], "go\n");
        $winnerStarted = true;
        $deadline = microtime(true) + 8;
        $blocked = false;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $row = DB::selectOne('SELECT ?::integer=ANY(pg_blocking_pids(?::integer)) AS blocked', [$children['old']['backend'], $children['winner']['backend']]);
            $blocked = $row->blocked && DB::table('market_attempts')->where('ordinal', 2)->count() === 1;
            if ($blocked) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($blocked)->toBeTrue();
        DB::transaction(function (): void {
            expect(DB::selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?,0)) AS acquired', ['hwo:twelve-data'])->acquired)->toBeTrue();
        });
        fwrite($children['old']['pipe'], "release_http\n");
        $httpReleased = true;
        $stage = json_decode((string) fgets($children['old']['pipe']), true, flags: JSON_THROW_ON_ERROR);
        expect($stage['stage'])->toBe('fetch_committed');
        $winner = json_decode((string) fgets($children['winner']['pipe']), true, flags: JSON_THROW_ON_ERROR);
        expect($winner['status'])->toBe('accepted')->and($winner['actual_fake_sends'])->toBe(1);
        expect((string) DB::table('fx_observations')->value('value'))->toBe('3.650000000000');
        expect(DB::table('market_results')->where('status', 'accepted')->count())->toBe(1);
        fwrite($children['old']['pipe'], "release_result\n");
        $resultReleased = true;
        $old = json_decode((string) fgets($children['old']['pipe']), true, flags: JSON_THROW_ON_ERROR);
        expect($old)->toMatchArray(['status' => 'late', 'code' => 'late_ignored', 'actual_fake_sends' => 1]);

        return ['old' => $old, 'winner' => $winner, 'owner_lock_wait_observed' => true, 'provider_lock_free_during_http' => true];
    } finally {
        if (isset($children['old'])) {
            if (! $httpReleased) {
                @fwrite($children['old']['pipe'], "release_http\n");
            }
            if (! $resultReleased) {
                @fwrite($children['old']['pipe'], "release_result\n");
            }
        }
        if (isset($children['winner']) && ! $winnerStarted) {
            @fwrite($children['winner']['pipe'], "go\n");
        }
        foreach ($children as $child) {
            fclose($child['pipe']);
            pcntl_waitpid($child['pid'], $status);
            expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
        }
    }
}
