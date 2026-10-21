<?php

use App\Domain\Shariah\PurificationRead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\PurificationFixtures as F;
use Tests\TestCase;

require_once __DIR__.'/../Support/PurificationRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->owner = User::factory()->create();
    $this->f = F::distribution($this->owner);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

test('duplicate obligation requests contend on distinct PostgreSQL backends and recover one exact receipt', function () {
    $c = ['op' => 'obligation', 'key' => 'synthetic-race-obligation', 'input' => F::input($this->owner, $this->f)];
    $results = concurrentPurificationRace($this->owner->id, [$c, $c]);
    expect(array_column($results, 'status'))->toBe(['success', 'success'])->and($results[0]['id'])->toBe($results[1]['id']);
    $this->assertDatabaseCount('purification_obligations', 1);
});
test('distinct retry keys cannot duplicate the same distribution and method business identity', function () {
    $c = ['op' => 'obligation', 'input' => F::input($this->owner, $this->f)];
    $r = concurrentPurificationRace($this->owner->id, [[...$c, 'key' => 'synthetic-race-business-a'], [...$c, 'key' => 'synthetic-race-business-b']]);
    $statuses = array_column($r, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('purification_obligations', 1);
});
test('competing corrections retain one successor and never branch or overwrite payment history', function () {
    $o = F::obligation($this->owner, $this->f);
    $c = ['op' => 'obligation', 'input' => F::input($this->owner, $this->f, ['supersedes_id' => (string) $o->id, 'method_version' => 'Synthetic v2', 'rate_percent' => '3'])];
    $r = concurrentPurificationRace($this->owner->id, [[...$c, 'key' => 'synthetic-race-revision-a'], [...$c, 'key' => 'synthetic-race-revision-b']]);
    $statuses = array_column($r, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('purification_obligations', 2);
});
test('duplicate actual payment requests commit one journal and recover its receipt under contention', function () {
    $before = DB::table('journal_entries')->count();
    $c = ['op' => 'payment', 'key' => 'synthetic-race-payment', 'input' => F::paymentInput($this->f)];
    $r = concurrentPurificationRace($this->owner->id, [$c, $c]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id'])->and(DB::table('journal_entries')->count())->toBe($before + 1);
    $this->assertDatabaseCount('purification_payments', 1);
});
test('concurrent allocations to separate obligations cannot spend the same actual payment twice', function () {
    $a = F::obligation($this->owner, $this->f);
    $b = F::obligation($this->owner, $this->f, ['method_reference' => 'SYNTHETIC-SECOND']);
    $p = F::payment($this->owner, $this->f);
    $input = ['payment_id' => (string) $p->id, 'amount' => '1.50', 'reason' => 'Synthetic allocation contention'];
    $r = concurrentPurificationRace($this->owner->id, [
        ['op' => 'allocate', 'key' => 'synthetic-race-allocation-a', 'input' => [...$input, 'obligation_id' => (string) $a->id]],
        ['op' => 'allocate', 'key' => 'synthetic-race-allocation-b', 'input' => [...$input, 'obligation_id' => (string) $b->id]],
    ]);
    $statuses = array_column($r, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation'])->and(app(PurificationRead::class)->used($this->owner, (string) $p->id))->toBe('150');
});
test('concurrent separate payments cannot over-settle one obligation', function () {
    $o = F::obligation($this->owner, $this->f);
    $a = F::payment($this->owner, $this->f);
    $b = F::payment($this->owner, $this->f);
    $input = ['obligation_id' => (string) $o->id, 'amount' => '1.50', 'reason' => 'Synthetic outstanding contention'];
    $r = concurrentPurificationRace($this->owner->id, [
        ['op' => 'allocate', 'key' => 'synthetic-race-outstanding-a', 'input' => [...$input, 'payment_id' => (string) $a->id]],
        ['op' => 'allocate', 'key' => 'synthetic-race-outstanding-b', 'input' => [...$input, 'payment_id' => (string) $b->id]],
    ]);
    $statuses = array_column($r, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation'])->and(app(PurificationRead::class)->paid($this->owner, $o->series_id))->toBe('150');
});
test('allocation racing actual journal reversal preserves history and leaves no ghost settlement', function () {
    $o = F::obligation($this->owner, $this->f);
    $p = F::payment($this->owner, $this->f);
    $r = concurrentPurificationRace($this->owner->id, [
        ['op' => 'allocate', 'key' => 'synthetic-race-reverse-allocation', 'input' => ['obligation_id' => (string) $o->id, 'payment_id' => (string) $p->id, 'amount' => '2', 'reason' => 'Synthetic reverse contention']],
        ['op' => 'reverse', 'key' => 'synthetic-race-payment-reversal', 'journal_id' => (string) $p->journal_id],
    ]);
    expect($r[1]['status'])->toBe('success')->and($r[0]['status'])->toBeIn(['success', 'validation'])->and(app(PurificationRead::class)->paid($this->owner, $o->series_id))->toBe('0');
    expect(app(PurificationRead::class)->detail($this->owner, (string) $o->id)['obligation']['status'])->toBe('outstanding');
});
test('committed immutable purification records reject updates deletes forged attribution and overcapacity inserts', function () {
    $o = F::obligation($this->owner, $this->f);
    $p = F::payment($this->owner, $this->f);
    $a = F::allocate($this->owner, $o, $p, '1');
    foreach (['purification_obligations' => $o, 'purification_payments' => $p, 'purification_allocations' => $a] as $table => $row) {
        foreach (['update', 'delete'] as $op) {
            expect(fn () => DB::transaction(fn () => $op === 'delete' ? DB::table($table)->where('id', $row->id)->delete() : DB::table($table)->where('id', $row->id)->update(['reason' => 'Forbidden overwrite'])))->toThrow(PDOException::class);
        }
    }
    foreach ([['amount_minor' => '101'], ['actor_is_admin' => true], ['currency' => 'USD']] as $change) {
        $raw = (array) $a;
        unset($raw['id']);
        $raw = [...$raw, 'reference' => (string) Str::ulid(), 'idempotency_key' => (string) Str::uuid(), ...$change];
        expect(fn () => DB::transaction(fn () => DB::table('purification_allocations')->insert($raw)))->toThrow(PDOException::class);
    }
    $raw = (array) $o;
    unset($raw['id']);
    foreach ([['due_minor' => '0'], ['base_minor' => '9999'], ['distribution_lineage' => '999'], ['actor_id' => '999']] as $change) {
        $candidate = [...$raw, 'reference' => (string) Str::ulid(), 'series_id' => (string) Str::ulid(), 'method_reference' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), ...$change];
        expect(fn () => DB::transaction(fn () => DB::table('purification_obligations')->insert($candidate)))->toThrow(PDOException::class);
    }
});
test('purification migration refuses retained evidence before DDL and empty roundtrip preserves earlier financial facts', function () {
    $decisions = require database_path('migrations/2026_10_21_064500_create_decision_records.php');
    $decisions->down();
    $m = require database_path('migrations/2026_10_20_073000_create_purification_records.php');
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    $m->down();
    expect(Schema::hasTable('purification_obligations'))->toBeFalse();
    $m->up();
    $decisions->up();
    expect(Schema::hasTable('purification_obligations'))->toBeTrue()->and(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before);
    F::obligation($this->owner, $this->f);
    expect(fn () => $m->down())->toThrow(RuntimeException::class, 'before DDL');
    expect(Schema::hasTable('purification_payments'))->toBeTrue();
});

test('a coherent financial read does not mix a concurrently committed obligation correction', function () {
    $first = F::obligation($this->owner, $this->f);
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
                if (! $paused && str_contains($query->sql, 'from "purification_obligations"')) {
                    $paused = true;
                    fwrite($pair[1], json_encode(['isolation' => DB::selectOne('SHOW transaction_isolation')->transaction_isolation])."\n");
                    if (trim((string) fgets($pair[1])) !== 'go') {
                        throw new RuntimeException('Missing reader barrier');
                    }
                }
            });
            $v = app(PurificationRead::class)->detail(User::findOrFail($this->owner->id), (string) $first->id);
            fwrite($pair[1], json_encode(['status' => 'success', 'version' => $v['obligation']['version'], 'due' => $v['obligation']['due']['minor'], 'history' => count($v['history']['items'])])."\n");
        } catch (Throwable $e) {
            fwrite($pair[1], json_encode(['status' => 'error', 'message' => $e->getMessage()])."\n");
        }
        fclose($pair[1]);
        DB::disconnect();
        exit(0);
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 20);
    $ready = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
    expect($ready['isolation'])->toBe('repeatable read');
    F::obligation($this->owner, $this->f, ['supersedes_id' => (string) $first->id, 'rate_percent' => '3', 'method_version' => 'Synthetic v2']);
    fwrite($pair[0], "go\n");
    $result = json_decode((string) fgets($pair[0]), true, flags: JSON_THROW_ON_ERROR);
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);
    expect($result)->toBe(['status' => 'success', 'version' => '1', 'due' => '200', 'history' => 1])->and(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
    expect(app(PurificationRead::class)->detail($this->owner, (string) $first->id)['obligation']['version'])->toBe('2');
});
