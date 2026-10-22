<?php

use App\Domain\Imports\StatementImports;
use App\Domain\Ledger\JournalPosted;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FxFixtures;
use Tests\Support\GoalFixtures;
use Tests\Support\StatementFixtures as S;
use Tests\TestCase;

require_once __DIR__.'/../Support/StatementRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->owner = User::factory()->create();
    $this->bank = FxFixtures::account($this->owner);
    $this->imports = app(StatementImports::class);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('same-key import contention across actual blocked PostgreSQL backends recovers one batch', function () {
    $s = S::preview($this->owner, $this->bank, [['provider_id' => 'SYNTHETIC-RACE']]);
    $command = ['op' => 'approve', 'snapshot' => (string) $s->id, 'selection' => ['1'], 'key' => 'synthetic-import-race-same-key'];
    $results = concurrentStatementRace($this->owner->id, [$command, $command]);
    expect(array_column($results, 'status'))->toBe(['success', 'success'])->and($results[0]['id'])->toBe($results[1]['id']);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('statement_receipts', 1);
});

test('different-key concurrent reexports retain two request receipts and one logical posting', function () {
    $bytes = S::bytes([['provider_id' => 'SYNTHETIC-CROSS-SOURCE-RACE']]);
    $a = $this->imports->preview($this->owner, $bytes, 'Synthetic same stream', (string) $this->bank->id, 'Synthetic mapping', S::mapping());
    $b = $this->imports->preview($this->owner, str_replace("\r\n", "\n", $bytes), 'Synthetic same stream', (string) $this->bank->id, 'Synthetic mapping', S::mapping());
    $c = ['op' => 'approve', 'selection' => ['1']];
    $results = concurrentStatementRace($this->owner->id, [[...$c, 'snapshot' => (string) $a->id, 'key' => 'synthetic-reexport-race-a'], [...$c, 'snapshot' => (string) $b->id, 'key' => 'synthetic-reexport-race-b']]);
    expect(array_column($results, 'status'))->toBe(['success', 'success']);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('statement_receipts', 2);
    $types = DB::table('statement_receipts')->get()->map(fn ($r) => json_decode($r->outcomes, true, flags: JSON_THROW_ON_ERROR)['1']['type'])->sort()->values()->all();
    expect($types)->toBe(['created', 'duplicate']);
});

test('competing provider conflicts and corrections cannot branch financial history', function () {
    $a = S::preview($this->owner, $this->bank, [['provider_id' => 'SYNTHETIC-CONFLICT', 'amount' => '2.34']]);
    $b = S::preview($this->owner, $this->bank, [['provider_id' => 'SYNTHETIC-CONFLICT', 'amount' => '3.45']]);
    $c = ['op' => 'approve', 'selection' => ['1']];
    $results = concurrentStatementRace($this->owner->id, [[...$c, 'snapshot' => (string) $a->id, 'key' => 'synthetic-conflict-race-a'], [...$c, 'snapshot' => (string) $b->id, 'key' => 'synthetic-conflict-race-b']]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('journal_entries', 1);
    $receipt = (string) DB::table('statement_receipts')->value('id');
    $c = ['op' => 'correct', 'receipt' => $receipt, 'token' => '1', 'reason' => 'Synthetic competing correction'];
    $results = concurrentStatementRace($this->owner->id, [[...$c, 'key' => 'synthetic-correction-race-a'], [...$c, 'key' => 'synthetic-correction-race-b']]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('journal_entries', 2);
    $this->assertDatabaseCount('statement_receipts', 2);
    expect(DB::table('journal_entries')->whereNotNull('reverses_journal_id')->count())->toBe(1);
});

test('after-commit response loss recovers the durable import receipt before archived eligibility', function () {
    $s = S::preview($this->owner, $this->bank, [[]]);
    $listeners = Event::getRawListeners()[JournalPosted::class] ?? [];
    Event::listen(JournalPosted::class, function (): void {
        throw new RuntimeException('Synthetic lost response after durable commit');
    });
    try {
        expect(fn () => $this->imports->approve($this->owner, (string) $s->id, 'synthetic-after-commit-import', ['1']))->toThrow(RuntimeException::class, 'after durable commit');
    } finally {
        Event::forget(JournalPosted::class);
        foreach ($listeners as $listener) {
            Event::listen(JournalPosted::class, $listener);
        }
    }
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('statement_receipts', 1);
    DB::table('accounts')->where('id', $this->bank->id)->update(['archived_at' => now()]);
    $r = $this->imports->approve($this->owner, (string) $s->id, 'synthetic-after-commit-import', ['1']);
    expect((string) $r->id)->toBe((string) DB::table('statement_receipts')->value('id'));
    $this->assertDatabaseCount('journal_entries', 1);
});

test('import evidence is immutable and retained history refuses downgrade before any DDL', function () {
    $s = S::preview($this->owner, $this->bank, [[]]);
    $this->imports->approve($this->owner, (string) $s->id, 'synthetic-immutable-import', ['1']);
    foreach (['adapters', 'sources', 'snapshots', 'receipts', 'claims'] as $suffix) {
        $table = 'statement_'.$suffix;
        foreach (['delete', 'update'] as $operation) {
            expect(fn () => DB::transaction(fn () => $operation === 'delete' ? DB::table($table)->delete() : DB::table($table)->update(['owner_id' => $this->owner->id])))->toThrow(PDOException::class, 'immutable');
        }
    }
    $migration = require database_path('migrations/2026_10_22_100000_create_statement_imports.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'downgrade refused before DDL');
    expect(Schema::hasTable('statement_claims'))->toBeTrue()->and(DB::table('statement_receipts')->count())->toBe(1);
});

test('empty import schema roundtrip preserves all preceding financial bytes', function () {
    GoalFixtures::cash($this->owner, $this->bank, '12.34');
    $before = DB::table('journal_entries')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_10_22_100000_create_statement_imports.php');
    $migration->down();
    expect(Schema::hasTable('statement_sources'))->toBeFalse();
    $migration->up();
    expect(DB::table('journal_entries')->orderBy('id')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('statement_sources', 0);
});
