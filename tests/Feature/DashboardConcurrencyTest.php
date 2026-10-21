<?php

use App\Domain\Reporting\DashboardSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DashboardFixtures as D;
use Tests\Support\FxFixtures as F;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

require_once __DIR__.'/../Support/DashboardRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->owner = User::factory()->create();
    $this->a = F::account($this->owner);
    G::cash($this->owner, $this->a, '100');
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
test('same-key snapshot contention across actual blocked PostgreSQL backends recovers one retained receipt', function () {
    $c = ['op' => 'snapshot', 'key' => 'synthetic-race-snapshot', 'input' => ['base_currency' => 'QAR', 'settings_id' => '']];
    $r = concurrentDashboardRace($this->owner->id, [$c, $c]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('dashboard_snapshots', 1);
});
test('competing current rule revisions retain one successor and conflict the stale candidate', function () {
    $a = app(DashboardSnapshot::class)->configure($this->owner, 'synthetic-race-rules-root', D::settings());
    $c = ['op' => 'configure', 'input' => D::settings(['settings_id' => (string) $a->id])];
    $r = concurrentDashboardRace($this->owner->id, [[...$c, 'key' => 'synthetic-race-rules-a'], [...$c, 'key' => 'synthetic-race-rules-b']]);
    $statuses = array_column($r, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation']);
    $this->assertDatabaseCount('dashboard_settings', 2);
});
test('alternate-key simultaneous dismissals retain one logical episode and two durable request receipts', function () {
    $a = app(DashboardSnapshot::class)->current($this->owner, Currency::QAR)['actions'][0];
    $c = ['op' => 'dismiss', 'input' => ['snapshot_id' => '', 'base_currency' => 'QAR', 'action_key' => $a['key'], 'reason' => 'Synthetic race review']];
    $r = concurrentDashboardRace($this->owner->id, [[...$c, 'key' => 'synthetic-race-dismiss-a'], [...$c, 'key' => 'synthetic-race-dismiss-b']]);
    expect(array_column($r, 'status'))->toBe(['success', 'success']);
    $this->assertDatabaseCount('dashboard_dismissals', 1);
    $this->assertDatabaseCount('dashboard_dismissal_requests', 2);
});
test('snapshot racing a cash withdrawal retains one coherent cash and wealth basis', function () {
    $r = concurrentDashboardRace($this->owner->id, [['op' => 'snapshot', 'key' => 'synthetic-race-withdraw-snapshot', 'input' => ['base_currency' => 'QAR', 'settings_id' => '']], ['op' => 'withdraw', 'key' => 'synthetic-race-withdraw-post', 'input' => ['kind' => 'expense', 'account_id' => (string) $this->a->id, 'amount' => '25', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic cash race']]]);
    expect(array_column($r, 'status'))->toBe(['success', 'success']);
    $v = app(DashboardSnapshot::class)->open($this->owner, (string) $r[0]['id'])['facts'];
    expect($v['widgets']['net_worth']['total']['amount'])->toBeIn(['100.00', '75.00'])->and($v['cash']['accounts'][0]['minor'])->toBe($v['widgets']['net_worth']['total']['minor']);
});
test('all retained dashboard annotation tables reject committed mutation and downgrade before any DDL', function () {
    $service = app(DashboardSnapshot::class);
    $settings = $service->configure($this->owner, 'synthetic-retain-settings', D::settings());
    $s = $service->save($this->owner, 'synthetic-retain-snapshot', ['base_currency' => 'QAR', 'settings_id' => (string) $settings->id]);
    $a = $service->open($this->owner, (string) $s->id)['actions'][0];
    $request = $service->dismiss($this->owner, 'synthetic-retain-dismiss', ['snapshot_id' => (string) $s->id, 'base_currency' => 'QAR', 'action_key' => $a['key'], 'reason' => 'Retained synthetic review']);
    foreach (['dashboard_settings' => $settings->id, 'dashboard_snapshots' => $s->id, 'dashboard_dismissals' => $request->dismissal_id, 'dashboard_dismissal_requests' => $request->id] as $table => $id) {
        foreach (['update', 'delete'] as $op) {
            expect(fn () => DB::transaction(fn () => $op === 'delete' ? DB::table($table)->where('id', $id)->delete() : DB::table($table)->where('id', $id)->update(['reference' => 'FORBIDDEN'])))->toThrow(PDOException::class);
        }
    }
    $m = require database_path('migrations/2026_10_21_083000_create_dashboard_evidence.php');
    expect(fn () => $m->down())->toThrow(RuntimeException::class, 'before DDL');
    expect(Schema::hasTable('dashboard_snapshots'))->toBeTrue();
});
test('empty dashboard schema roundtrip preserves preceding ledger evidence', function () {
    $before = DB::table('postings')->get()->toJson();
    $m = require database_path('migrations/2026_10_21_083000_create_dashboard_evidence.php');
    $m->down();
    expect(Schema::hasTable('dashboard_settings'))->toBeFalse();
    $m->up();
    expect(DB::table('postings')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('dashboard_snapshots', 0);
});
