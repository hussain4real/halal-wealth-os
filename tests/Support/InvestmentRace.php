<?php

use App\Domain\Portfolio\InvestmentService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function concurrentInvestmentRace(int $ownerId, array $commands): array
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
                $service = app(InvestmentService::class);
                $journal = $command['operation'] === 'reverse'
                    ? $service->reverse($owner, $command['portfolio'], $command['event'], $command['key'], 'Synthetic racing cancellation')
                    : $service->record($owner, $command['portfolio'], $command['key'], $command['input']);
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
