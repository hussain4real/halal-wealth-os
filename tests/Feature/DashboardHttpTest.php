<?php

use App\Domain\Reporting\DashboardSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DashboardFixtures as D;
use Tests\Support\FxFixtures as F;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
    $this->owner = User::factory()->create();
    $a = F::account($this->owner);
    G::cash($this->owner, $a, '100');
});
test('dashboard authenticates and carries complete native base and asof source drilldown', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard')->where('dashboard.widgets.net_worth.total.amount', '100.00')->where('dashboard.cutoff', '2026-10-21T12:00:00Z')->has('dashboard.sources.net_worth', 1));
});
test('snapshot HTTP response retries to original receipt and exports retained evidence privately', function () {
    $payload = ['idempotency_key' => 'synthetic-http-snapshot', 'settings_id' => '', 'base_currency' => 'QAR'];
    $r = $this->actingAs($this->owner)->postJson('/dashboard/snapshots', $payload)->assertCreated();
    $id = $r->json('id');
    $this->postJson('/dashboard/snapshots', $payload)->assertCreated()->assertJsonPath('id', $id);
    $this->get('/dashboard/snapshots/'.$id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('snapshot.id', $id));
    $json = $this->get('/dashboard/snapshots/'.$id.'/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($json->json('facts.widgets.net_worth.total.amount'))->toBe('100.00');
});
test('foreign ordinary and admin users cannot read export or dismiss an owned snapshot action', function (bool $admin) {
    $s = app(DashboardSnapshot::class)->save($this->owner, 'synthetic-foreign-snapshot', ['base_currency' => 'QAR', 'settings_id' => '']);
    $action = app(DashboardSnapshot::class)->open($this->owner, (string) $s->id)['actions'][0];
    $other = User::factory()->create(['is_admin' => $admin]);
    $this->actingAs($other)->get('/dashboard/snapshots/'.$s->id)->assertNotFound();
    $this->get('/dashboard/snapshots/'.$s->id.'/export')->assertNotFound();
    $this->postJson('/dashboard/dismissals', ['idempotency_key' => 'synthetic-foreign-dismiss', 'snapshot_id' => (string) $s->id, 'base_currency' => 'QAR', 'action_key' => $action['key'], 'reason' => 'Unauthorized'])->assertNotFound();
    $this->assertDatabaseCount('dashboard_dismissals', 0);
})->with([false, true]);
test('HTTP boundary rejects owner role extra fields and nonstring money', function () {
    $this->actingAs($this->owner)->postJson('/dashboard/snapshots', ['idempotency_key' => 'synthetic-forged-owner', 'settings_id' => '', 'base_currency' => 'QAR', 'owner_id' => '999'])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    $this->postJson('/dashboard/rules', [...D::settings(), 'idempotency_key' => 'synthetic-forged-role', 'is_admin' => true])->assertUnprocessable()->assertJsonValidationErrors('is_admin');
    $this->assertDatabaseCount('dashboard_snapshots', 0);
    $this->assertDatabaseCount('dashboard_settings', 0);
});
test('rule and dismissal HTTP writes create no ledger posting and live action immediately shows suppression', function () {
    $before = DB::table('postings')->get()->toJson();
    $this->actingAs($this->owner)->postJson('/dashboard/rules', [...D::settings(), 'idempotency_key' => 'synthetic-http-settings'])->assertCreated();
    $a = app(DashboardSnapshot::class)->current($this->owner, Currency::QAR)['actions'][0];
    $this->postJson('/dashboard/dismissals', ['snapshot_id' => '', 'base_currency' => 'QAR', 'action_key' => $a['key'], 'reason' => 'Reviewed synthetic basis', 'idempotency_key' => 'synthetic-http-dismiss'])->assertCreated();
    $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('dashboard.actions.0.dismissed', true));
    expect(DB::table('postings')->get()->toJson())->toBe($before);
});

test('definitively stale snapshot and rule writes return editable validation errors without committing', function () {
    $this->actingAs($this->owner)->postJson('/dashboard/rules', [...D::settings(), 'idempotency_key' => 'synthetic-stale-policy-root'])->assertCreated();
    $this->postJson('/dashboard/snapshots', ['idempotency_key' => 'synthetic-definite-stale-snapshot', 'base_currency' => 'QAR', 'settings_id' => ''])->assertUnprocessable()->assertJsonValidationErrors('settings_id');
    $this->postJson('/dashboard/rules', [...D::settings(), 'idempotency_key' => 'synthetic-definite-stale-rules'])->assertUnprocessable()->assertJsonValidationErrors('settings_id');
    $this->assertDatabaseCount('dashboard_snapshots', 0);
    $this->assertDatabaseCount('dashboard_settings', 1);
});
test('generated reconciliation action routes and reporting FX destination resolve for the ordinary owner', function () {
    $v = app(DashboardSnapshot::class)->current($this->owner, Currency::QAR);
    $this->actingAs($this->owner);
    foreach ($v['actions'] as $a) {
        $this->get($a['url'])->assertOk();
    }
    $this->get('/rates')->assertOk();
});

test('rule settings page exposes only the owners current version without writing on read', function () {
    $this->get('/dashboard/rules')->assertRedirect('/login');
    $other = User::factory()->create();
    app(DashboardSnapshot::class)->configure($other, 'synthetic-other-page-settings', D::settings(['grace_days' => '31']));
    $settings = app(DashboardSnapshot::class)->configure($this->owner, 'synthetic-owner-page-settings', D::settings(['grace_days' => '7']));
    $this->actingAs($this->owner)->get('/dashboard/rules')->assertOk()->assertInertia(fn (Assert $p) => $p->component('dashboard/Rules')->where('settings.id', (string) $settings->id)->where('settings.version', '1')->where('settings.data.grace_days', '7'));
    $this->assertDatabaseCount('dashboard_settings', 2);
    $this->assertDatabaseCount('dashboard_snapshots', 0);
});
