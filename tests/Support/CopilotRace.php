<?php

use App\Domain\Intelligence\CopilotBoundary;
use App\Domain\Reporting\Copilot\ToolQuery;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Real backend barrier; each contender shares only committed database identities. */
function concurrentCopilotRace(int $ownerId, array $commands): array
{
    DB::disconnect();
    $children = [];
    foreach ($commands as $command) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false || ($pid = pcntl_fork()) < 0) {
            throw new RuntimeException('Required process barrier unavailable');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            DB::purge();
            $backend = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            fwrite($pair[1], json_encode(['backend' => $backend], JSON_THROW_ON_ERROR)."\n");
            if (trim((string) fgets($pair[1])) !== 'go') {
                exit(2);
            }
            try {
                Auth::login(User::findOrFail($ownerId));
                $result = app(CopilotBoundary::class)->preview($command['key'], ToolQuery::parse(['tool' => 'cash_availability', 'arguments' => []]));
                $result = ['status' => 'ok', 'outcome' => $result['status'], 'backend' => $backend];
            } catch (HttpException $e) {
                $result = ['status' => $e->getStatusCode(), 'backend' => $backend];
            } catch (Throwable $e) {
                $result = ['status' => 'error', 'type' => $e::class, 'backend' => $backend];
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
        $backends[] = json_decode((string) fgets($child['pipe']), true, flags: JSON_THROW_ON_ERROR)['backend'];
    }
    expect(count(array_unique($backends)))->toBe(count($commands));
    DB::beginTransaction();
    try {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['hwo:posting:owner:'.$ownerId]);
        foreach ($children as $child) {
            fwrite($child['pipe'], "go\n");
        }
        $deadline = microtime(true) + 8;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', $backends)->where('wait_event_type', 'Lock')->whereIn('wait_event', ['advisory', 'transactionid'])->count();
            if ($waiting === count($children)) {
                break;
            }
            usleep(10000);
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
