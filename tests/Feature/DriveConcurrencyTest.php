<?php

use App\Domain\Integrations\Drive\DriveExports;
use App\Domain\Integrations\Drive\DriveFailure;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DriveFixtures as D;
use Tests\TestCase;

require_once __DIR__.'/../Support/DriveRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->owner = User::factory()->create();
    $this->wire = new D;
    $this->wire->fake();
    $this->drive = app(DriveExports::class);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('separate PostgreSQL duplicate confirmations recover one durable report intent', function () {
    $this->wire->ready($this->owner);
    $review = $this->drive->review($this->owner, D::snapshot($this->owner));
    $commands = array_fill(0, 2, ['op' => 'confirm', 'key' => 'concurrent-drive-01', 'token' => $review['review_token']]);
    expect(array_column(concurrentDriveRace($this->owner->id, $commands), 'status'))->toBe(['ok', 'ok']);
    expect(DB::table('drive_operations')->where('kind', 'report')->count())->toBe(1);
});

test('separate PostgreSQL workers serialize one remote mutation claim', function () {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    concurrentDriveRace($this->owner->id, [['op' => 'run', 'id' => $r['id']], ['op' => 'run', 'id' => $r['id']]]);
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'uploaded', 'attempts' => 1]);
    expect(DB::table('drive_events')->where('operation_id', $r['id'])->where('safe_code', 'claimed')->count())->toBe(1);
});

test('disconnect committed between durable claim and handoff blocks upload and credential use', function () {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $once = true;
    Event::listen(TransactionCommitted::class, function ($event) use (&$once, $c, $r) {
        if ($once && $event->connection->transactionLevel() === 0 && DB::table('drive_operations')->where('id', $r['id'])->value('state') === 'claimed') {
            $once = false;
            $this->drive->disconnect($this->owner, $c['id']);
        }
    });
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('unknown')->and($this->wire->creates)->toBe(1);
});

test('separate PostgreSQL disconnect and worker race ends locally stopped without a duplicate intent', function () {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    concurrentDriveRace($this->owner->id, [['op' => 'run', 'id' => $r['id']], ['op' => 'disconnect', 'id' => $c['id']]]);
    expect($this->drive->connection($this->owner, $c['id'])['status'])->toBe('disconnected');
    $state = $this->drive->inspect($this->owner, $r['request_key'])['state'];
    expect($state)->toBeIn(['uploaded', 'cancelled', 'unknown']);
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe($state);
});

test('remote success followed by local result loss reconciles retained ID after crash', function () {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $once = true;
    DB::listen(function ($event) use (&$once) {
        if ($once && str_starts_with($event->sql, 'update "drive_operations"') && in_array('remote_evidence_verified', $event->bindings, true)) {
            $once = false;
            throw new RuntimeException('Synthetic local completion loss');
        }
    });
    expect(fn () => $this->drive->run($r['id']))->toThrow(RuntimeException::class, 'Synthetic local completion loss');
    expect(DB::table('drive_operations')->where('id', $r['id'])->value('state'))->toBe('claimed');
    $this->travel(3)->minutes();
    $this->drive->run($r['id']);
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('uploaded')->and($this->wire->creates)->toBe(2);
});

test('held restored low IDs cannot starve a current epoch operation', function () {
    $this->wire->ready($this->owner);
    $id = D::snapshot($this->owner);
    for ($i = 0; $i < 32; $i++) {
        D::queued($this->owner, $id);
    }
    config(['drive_export.epoch' => str_repeat('b', 32)]);
    $current = User::factory()->create();
    $this->wire->ready($current);
    $r = D::queued($current, D::snapshot($current));
    $this->drive->tick();
    expect($this->drive->inspect($current, $r['request_key'])['state'])->toBe('uploaded');
    expect(DB::table('drive_operations')->where('owner_id', $this->owner->id)->where('state', 'queued')->count())->toBe(32);
});

test('operation capacity never blocks receipt recovery inspection or local disconnect', function () {
    $c = $this->wire->ready($this->owner);
    $id = D::snapshot($this->owner);
    for ($i = 0; $i < 63; $i++) {
        $r = D::queued($this->owner, $id);
    }
    $review = $this->drive->review($this->owner, $id);
    expect(fn () => $this->drive->confirm($this->owner, 'capacity-reject-01', $review['review_token'], true))->toThrow(DriveFailure::class, 'retained_capacity');
    expect($this->drive->inspect($this->owner, $r['request_key'])['state'])->toBe('queued');
    $this->drive->disconnect($this->owner, $c['id']);
    expect($this->drive->connection($this->owner, $c['id'])['status'])->toBe('disconnected');
});

test('Drive migration refuses populated downgrade and guards retained request and owner identities', function () {
    $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    foreach (['drive_controls', 'drive_connections', 'drive_operations', 'drive_events'] as $table) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->delete()))->toThrow(PDOException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('drive_operations')->where('id', $r['id'])->update(['content_hash' => str_repeat('b', 64)])))->toThrow(PDOException::class);
    $migration = require database_path('migrations/2026_10_24_100000_create_drive_exports.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retained Drive evidence');
    expect(Schema::hasTable('drive_secrets'))->toBeTrue();
});

test('competing revoke callers cannot consume the active permit or erase its credential before handoff', function () {
    $c = $this->wire->connect($this->owner);
    $once = true;
    Event::listen(TransactionCommitted::class, function ($event) use (&$once, $c) {
        if ($once && $event->connection->transactionLevel() === 0 && DB::table('drive_connections')->where('id', $c['id'])->value('revoke_attempts') === 1) {
            $once = false;
            for ($i = 0; $i < 4; $i++) {
                $this->drive->revoke($c['id']);
            }
            expect(DB::table('drive_connections')->where('id', $c['id'])->value('revoke_attempts'))->toBe(1);
            expect(DB::table('drive_secrets')->where('connection_id', $c['id'])->exists())->toBeTrue();
        }
    });
    $this->drive->disconnect($this->owner, $c['id']);
    expect($this->drive->connection($this->owner, $c['id'])['revoke_state'])->toBe('confirmed');
    expect(DB::table('drive_connections')->where('id', $c['id'])->value('revoke_attempts'))->toBe(1);
});

test('disconnect preserves a possibly committed remote report as stopped unknown rather than cancelled', function () {
    $c = $this->wire->ready($this->owner);
    $r = D::queued($this->owner, D::snapshot($this->owner));
    $this->wire->failure = 'after_create';
    $this->drive->run($r['id']);
    $this->wire->failure = '';
    $this->drive->disconnect($this->owner, $c['id']);
    $this->travel(2)->minutes();
    $this->drive->tick();
    expect($this->drive->inspect($this->owner, $r['request_key']))->toMatchArray(['state' => 'unknown', 'safe_code' => 'stopped_remote_unconfirmed']);
    $creates = $this->wire->creates;
    $this->drive->tick();
    expect($this->wire->creates)->toBe($creates)->and(isset($this->wire->files[$r['remote_id']]))->toBeTrue();
});

test('hard owner export overflow compensates a rejected callback without retaining credentials or raw errors', function () {
    DB::statement("INSERT INTO dashboard_snapshots(owner_id,reference,idempotency_key,payload_hash,evidence,evidence_hash) VALUES (?,?,'synthetic-drive-hard-capacity',?,jsonb_build_object('synthetic',repeat('x',67108864)),?)", [$this->owner->id, (string) Str::ulid(), hash('sha256', 'synthetic'), hash('sha256', 'synthetic')]);
    expect(fn () => $this->wire->connect($this->owner))->toThrow(DriveFailure::class, 'owner_export_capacity');
    $this->assertDatabaseCount('drive_connections', 0);
    $this->assertDatabaseCount('drive_secrets', 0);
    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke');
});
