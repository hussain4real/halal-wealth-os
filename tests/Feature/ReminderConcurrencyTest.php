<?php

use App\Domain\Notifications\Reminders;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ReminderFixtures as N;
use Tests\TestCase;

require_once __DIR__.'/../Support/ReminderRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));
    $this->owner = User::factory()->create();
    Mail::fake();
    $this->service = app(Reminders::class);
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    $this->service->plan($this->owner);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('separate PostgreSQL duplicate in app workers publish one atomic logical notification', function () {
    $id = N::channel($this->owner, 'in_app');
    $results = concurrentReminderRace($this->owner->id, [['op' => 'dispatch', 'id' => $id], ['op' => 'dispatch', 'id' => $id]]);
    expect(array_column($results, 'status'))->toBe(['in_app', 'in_app']);
    $this->assertDatabaseCount('reminder_claims', 1);
    $this->assertDatabaseCount('reminder_results', 1);
});

test('separate PostgreSQL duplicate email workers cannot reconstruct the creators handoff permit', function () {
    $id = N::channel($this->owner, 'email');
    $results = concurrentReminderRace($this->owner->id, [['op' => 'dispatch', 'id' => $id], ['op' => 'dispatch', 'id' => $id]]);
    expect(array_column($results, 'status'))->each->toBeIn(['mock_smtp', 'uncertain']);
    $this->assertDatabaseCount('reminder_claims', 1);
    $this->assertDatabaseCount('reminder_results', 1);
    expect(DB::table('reminder_results')->value('outcome'))->toBe('mock_smtp');
});

test('crash after durable SMTP claim leaves an inspectable unknown and scanner never resends', function () {
    $crash = true;
    Event::listen(TransactionCommitted::class, function ($event) use (&$crash) {
        if ($crash && $event->connection->transactionLevel() === 0 && DB::table('reminder_claims')->exists()) {
            $crash = false;
            throw new RuntimeException('Synthetic worker lost after committed claim');
        }
    });
    $id = N::channel($this->owner, 'email');
    expect(fn () => $this->service->dispatch($id))->toThrow(RuntimeException::class, 'Synthetic worker lost');
    expect($this->service->dispatch($id)['status'])->toBe('uncertain');
    $this->service->tick();
    expect(DB::table('reminder_claims')->where('channel_id', $id)->count())->toBe(1);
    expect(DB::table('reminder_results')->where('outcome', 'mock_smtp')->count())->toBe(0);
    Mail::assertNothingSent();
});

test('pause committed between claim and handoff blocks simulated SMTP', function () {
    $once = true;
    Event::listen(TransactionCommitted::class, function ($event) use (&$once) {
        if ($once && $event->connection->transactionLevel() === 0 && DB::table('reminder_claims')->exists()) {
            $once = false;
            $this->service->pause($this->owner);
        }
    });
    expect($this->service->dispatch(N::channel($this->owner, 'email')))->toMatchArray(['status' => 'retryable', 'code' => 'policy_changed']);
    Mail::assertNothingSent();
    expect(DB::table('reminder_results')->value('outcome'))->toBe('retryable');
});

test('lost in app transaction rolls back both claim and publication and recovers once', function () {
    $once = true;
    DB::listen(function ($event) use (&$once) {
        if ($once && str_starts_with($event->sql, 'insert into "reminder_results"')) {
            $once = false;
            throw new RuntimeException('Synthetic in app transaction lost');
        }
    });
    $id = N::channel($this->owner, 'in_app');
    expect(fn () => $this->service->dispatch($id))->toThrow(RuntimeException::class, 'Synthetic in app transaction lost');
    $this->assertDatabaseCount('reminder_claims', 0);
    $this->assertDatabaseCount('reminder_results', 0);
    expect($this->service->dispatch($id)['status'])->toBe('in_app');
    $this->assertDatabaseCount('reminder_claims', 1);
    $this->assertDatabaseCount('reminder_results', 1);
});

test('reminder history remains immutable and populated downgrade refuses before DDL', function () {
    $this->service->dispatch(N::channel($this->owner, 'in_app'));
    $this->service->read($this->owner, (string) DB::table('reminder_notifications')->value('id'));
    foreach (['preferences', 'notifications', 'channels', 'claims', 'results', 'reads'] as $table) {
        expect(fn () => DB::transaction(fn () => DB::table('reminder_'.$table)->update(['id' => DB::raw('id')])))->toThrow(PDOException::class, 'immutable');
        expect(fn () => DB::transaction(fn () => DB::table('reminder_'.$table)->delete()))->toThrow(PDOException::class, 'immutable');
    }
    expect(fn () => DB::transaction(fn () => DB::table('reminder_controls')->delete()))->toThrow(PDOException::class, 'Retained reminder control');
    $migration = require database_path('migrations/2026_10_24_000001_create_reminder_outbox.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'downgrade refused');
    expect(Schema::hasTable('reminder_results'))->toBeTrue();
});
