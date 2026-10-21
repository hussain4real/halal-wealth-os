<?php

use App\Domain\Shariah\AssessmentRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AssessmentFixtures as A;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

require_once __DIR__.'/../Support/AssessmentRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->owner = User::factory()->create();
    $this->asset = P::asset($this->owner);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

test('duplicate evidence requests on separate contending PostgreSQL connections recover one receipt', function () {
    $command = ['asset_id' => (string) $this->asset->id, 'key' => 'synthetic-racing-assessment', 'input' => A::input($this->owner, $this->asset)];
    $results = concurrentAssessmentRace($this->owner->id, [$command, $command]);
    expect(array_column($results, 'status'))->toBe(['success', 'success'])->and($results[0]['id'])->toBe($results[1]['id']);
    $this->assertDatabaseCount('shariah_assessments', 1);
});
test('competing source revisions have one retained successor without lost or branching history', function () {
    $first = A::record($this->owner, $this->asset);
    $command = ['asset_id' => (string) $this->asset->id, 'input' => A::input($this->owner, $this->asset, ['supersedes_id' => (string) $first->id])];
    $results = concurrentAssessmentRace($this->owner->id, [[...$command, 'key' => 'synthetic-revision-race-a'], [...$command, 'key' => 'synthetic-revision-race-b']]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('shariah_assessments', 2);
});
test('independent conflicting sources both survive simultaneous evidence recording', function () {
    $results = concurrentAssessmentRace($this->owner->id, [
        ['asset_id' => (string) $this->asset->id, 'key' => 'synthetic-conflict-race-a', 'input' => A::input($this->owner, $this->asset)],
        ['asset_id' => (string) $this->asset->id, 'key' => 'synthetic-conflict-race-b', 'input' => A::input($this->owner, $this->asset, ['provider' => 'Synthetic source B', 'classification' => 'non_compliant'])],
    ]);
    expect(array_column($results, 'status'))->toBe(['success', 'success']);
    $view = app(AssessmentRead::class)->view($this->owner, (string) $this->asset->id, now('UTC')->format('Y-m-d\TH:i:s\Z'));
    expect($view['summary']['conflict'])->toBeTrue()->and($view['summary']['source_count'])->toBe(2);
});
test('committed assessment rows reject update deletion source substitution and actor forgery', function () {
    $first = A::record($this->owner, $this->asset);
    foreach (['update', 'delete'] as $operation) {
        expect(fn () => DB::transaction(fn () => $operation === 'delete'
            ? DB::table('shariah_assessments')->where('id', $first->id)->delete()
            : DB::table('shariah_assessments')->where('id', $first->id)->update(['notes' => 'Overwrite'])))->toThrow(PDOException::class);
    }
    $other = User::factory()->create();
    $asset = P::asset($other);
    foreach ([['provider' => 'Substituted source'], ['actor_id' => $other->id], ['asset_id' => $asset->id], ['version' => 4], ['effective_on' => '2000-01-01']] as $change) {
        $raw = (array) $first;
        unset($raw['id']);
        $raw = [...$raw, 'reference' => (string) Str::ulid(), 'version' => 2, 'supersedes_id' => $first->id, 'idempotency_key' => (string) Str::uuid(), ...$change];
        expect(fn () => DB::transaction(fn () => DB::table('shariah_assessments')->insert($raw)))->toThrow(PDOException::class);
    }
    $this->assertDatabaseCount('shariah_assessments', 1);
});
test('retained assessment evidence refuses downgrade before any DDL', function () {
    A::record($this->owner, $this->asset);
    $purification = require database_path('migrations/2026_10_20_073000_create_purification_records.php');
    $purification->down();
    $migration = require database_path('migrations/2026_10_19_110000_create_shariah_assessment_evidence.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'before DDL');
    expect(Schema::hasTable('shariah_assessments'))->toBeTrue();
    $this->assertDatabaseCount('shariah_assessments', 1);
});
test('empty assessment migration roundtrip preserves pre-existing financial evidence', function () {
    $before = DB::table('portfolio_assets')->orderBy('id')->get()->toJson();
    $purification = require database_path('migrations/2026_10_20_073000_create_purification_records.php');
    $purification->down();
    $migration = require database_path('migrations/2026_10_19_110000_create_shariah_assessment_evidence.php');
    $migration->down();
    expect(Schema::hasTable('shariah_assessments'))->toBeFalse();
    $migration->up();
    $purification->up();
    expect(Schema::hasTable('shariah_assessments'))->toBeTrue();
    expect(DB::table('portfolio_assets')->orderBy('id')->get()->toJson())->toBe($before);
});
test('coherent current and history reads do not mix a concurrently committed correction', function () {
    $first = A::record($this->owner, $this->asset);
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
                if (! $paused && str_contains($query->sql, 'from "shariah_assessments"')) {
                    $paused = true;
                    fwrite($pair[1], json_encode(['isolation' => DB::selectOne('SHOW transaction_isolation')->transaction_isolation])."\n");
                    if (trim((string) fgets($pair[1])) !== 'go') {
                        throw new RuntimeException('Reader barrier missing');
                    }
                }
            });
            $view = app(AssessmentRead::class)->view(User::findOrFail($this->owner->id), (string) $this->asset->id, now('UTC')->format('Y-m-d\TH:i:s\Z'));
            fwrite($pair[1], json_encode(['status' => 'success', 'current_id' => $view['current'][0]['id'], 'history_count' => count($view['history']['items'])])."\n");
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
        $next = A::record($this->owner, $this->asset, ['supersedes_id' => (string) $first->id, 'classification' => 'non_compliant']);
    } finally {
        fwrite($pair[0], "go\n");
    }
    $result = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);
    expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
    expect($ready['isolation'])->toBe('repeatable read')->and($result)->toBe(['status' => 'success', 'current_id' => (string) $first->id, 'history_count' => 1]);
    expect(app(AssessmentRead::class)->view($this->owner, (string) $this->asset->id, now('UTC')->format('Y-m-d\TH:i:s\Z'))['current'][0]['id'])->toBe((string) $next->id);
});
