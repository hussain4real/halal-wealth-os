<?php

use App\Domain\Shariah\PurificationRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PurificationFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->f = F::distribution($this->owner);
    $this->actingAs($this->owner);
});
test('owner can discover distribution record obligation and inspect exact native amounts without float conversion', function () {
    $this->get('/purification')->assertInertia(fn (Assert $p) => $p->component('purification/Index')->has('obligations', 0));
    $this->get('/purification/new')->assertInertia(fn (Assert $p) => $p->component('purification/Choose')->has('distributions', 1));
    $this->get('/purification/new?distribution='.$this->f['distribution']->id)->assertInertia(fn (Assert $p) => $p->component('purification/Form')->where('distribution.amount.minor', '10000')->where('distribution.fee.minor', '100')->where('distribution.tax.minor', '500')->where('prior', null));
    $res = $this->postJson('/purification/obligations', ['idempotency_key' => 'synthetic-http-obligation', ...F::input($this->owner, $this->f)])->assertCreated();
    $this->get($res->json('url'))->assertInertia(fn (Assert $p) => $p->component('purification/Record')->where('obligation.due.minor', '200')->where('obligation.status', 'outstanding')->where('obligation.assessment.id', (string) $this->f['assessment']->id));
    $this->get('/purification/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('obligations.0.due.minor', '200')->assertJsonMissingPath('obligations.0.payload_hash')->assertJsonMissingPath('obligations.0.idempotency_key');
});
test('manual payment allocation release and exact retries preserve one actual debit over HTTP', function () {
    $o = F::obligation($this->owner, $this->f);
    $this->get('/purification/payments/new')->assertInertia(fn (Assert $p) => $p->component('purification/Payment')->has('accounts', 1));
    $input = ['idempotency_key' => 'synthetic-http-payment', ...F::paymentInput($this->f)];
    $p = $this->postJson('/purification/payments', $input)->assertCreated();
    $this->postJson('/purification/payments', $input)->assertCreated()->assertJsonPath('id', $p->json('id'));
    $this->get($p->json('url'))->assertInertia(fn (Assert $i) => $i->component('purification/PaymentRecord')->where('payment.amount.minor', '200'));
    $allocation = ['idempotency_key' => 'synthetic-http-allocation', 'obligation_id' => (string) $o->id, 'payment_id' => $p->json('id'), 'amount' => '1', 'reason' => 'Synthetic partial payment'];
    $a = $this->postJson('/purification/allocations', $allocation)->assertCreated();
    $this->postJson('/purification/allocations', $allocation)->assertCreated()->assertJsonPath('id', $a->json('id'));
    $this->get($a->json('url'))->assertInertia(fn (Assert $i) => $i->where('obligation.status', 'partially_paid')->where('obligation.outstanding.minor', '100'));
    $release = ['idempotency_key' => 'synthetic-http-release', 'reason' => 'Synthetic allocation correction'];
    $r = $this->postJson('/purification/allocations/'.$a->json('id').'/release', $release)->assertCreated();
    $this->postJson('/purification/allocations/'.$a->json('id').'/release', $release)->assertCreated()->assertJsonPath('id', $r->json('id'));
    $this->get($a->json('url'))->assertInertia(fn (Assert $i) => $i->where('obligation.status', 'outstanding')->where('allocations.0.active', false));
    $this->assertDatabaseCount('purification_payments', 1);
    $this->assertDatabaseCount('purification_allocations', 1);
    $this->assertDatabaseCount('purification_releases', 1);
});
test('F14 foreign reads writes evidence exports allocations and releases deny ordinary owners and admins', function (bool $admin) {
    $o = F::obligation($this->owner, $this->f);
    $p = F::payment($this->owner, $this->f);
    $a = F::allocate($this->owner, $o, $p);
    $other = User::factory()->create(['is_admin' => $admin]);
    $otherFixture = F::distribution($other);
    $otherO = F::obligation($other, $otherFixture);
    $otherP = F::payment($other, $otherFixture);
    $this->actingAs($other);
    foreach (['/purification/obligations/'.$o->id, '/purification/obligations/'.$o->id.'/revise', '/purification/payments/'.$p->id, '/purification/new?distribution='.$this->f['distribution']->id] as $url) {
        $this->getJson($url)->assertNotFound()->assertDontSee($o->reference)->assertDontSee($p->reference);
    }
    $this->postJson('/purification/obligations', ['idempotency_key' => 'synthetic-cross-owner-obligation', ...F::input($this->owner, $this->f)])->assertNotFound();
    $this->postJson('/purification/obligations', ['idempotency_key' => 'synthetic-cross-owner-assessment', ...F::input($other, $otherFixture, ['method_reference' => 'SYNTHETIC-FOREIGN-ASSESSMENT', 'assessment_id' => (string) $this->f['assessment']->id])])->assertNotFound();
    $this->postJson('/purification/obligations', ['idempotency_key' => 'synthetic-cross-owner-prior', ...F::input($other, $otherFixture, ['supersedes_id' => (string) $o->id])])->assertNotFound();
    $this->postJson('/purification/payments', ['idempotency_key' => 'synthetic-cross-owner-account', ...F::paymentInput($this->f)])->assertNotFound();
    $this->postJson('/purification/payments', ['idempotency_key' => 'synthetic-cross-owner-journal', ...F::paymentInput($otherFixture, ['mode' => 'link', 'journal_id' => (string) $p->journal_id, 'account_id' => '', 'amount' => '', 'effective_date' => '', 'description' => ''])])->assertNotFound();
    foreach ([[(string) $o->id, (string) $otherP->id], [(string) $otherO->id, (string) $p->id]] as [$oid, $pid]) {
        $this->postJson('/purification/allocations', ['idempotency_key' => 'synthetic-cross-owner-allocation', 'obligation_id' => $oid, 'payment_id' => $pid, 'amount' => '1', 'reason' => 'Synthetic denied attempt'])->assertNotFound();
    }
    $this->postJson('/purification/allocations/'.$a->id.'/release', ['idempotency_key' => 'synthetic-cross-owner-release', 'reason' => 'Synthetic denied release'])->assertNotFound();
    $this->get('/purification/export')->assertOk()->assertJsonCount(1, 'obligations')->assertJsonPath('obligations.0.id', (string) $otherO->id)->assertDontSee($o->reference)->assertDontSee($p->reference);
})->with([false, true]);
test('forged financial authority timestamps ownership and unknown fields are rejected without mutations', function (array $changes, string $field) {
    $this->postJson('/purification/obligations', ['idempotency_key' => 'synthetic-forged-obligation', ...F::input($this->owner, $this->f), ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('purification_obligations', 0);
})->with([
    [['owner_id' => '7'], 'owner_id'], [['actor_id' => '7'], 'actor_id'], [['actor_is_admin' => true], 'actor_is_admin'], [['recorded_at' => '2020-01-01'], 'recorded_at'],
    [['due_minor' => '0'], 'due_minor'], [['rate_percent' => '0.0000000000001'], 'rate_percent'], [['rate_percent' => -1], 'rate_percent'], [['custom_base' => '100'], 'custom_base'],
    [['base_kind' => 'market_value'], 'base_kind'], [['context' => ''], 'context'], [['method_reference' => []], 'method_reference'],
]);
test('malformed ids cursors and payment request fields fail closed', function () {
    $this->getJson('/purification?after[]=2')->assertNotFound();
    $this->getJson('/purification/new?distribution[]=2')->assertNotFound();
    $this->postJson('/purification/payments', ['idempotency_key' => 'synthetic-forged-payment', ...F::paymentInput($this->f), 'actor_id' => '1'])->assertUnprocessable();
    $this->postJson('/purification/allocations', ['idempotency_key' => [], 'obligation_id' => '1', 'payment_id' => '1', 'amount' => '1', 'reason' => 'Synthetic malformed request'])->assertUnprocessable();
});
test('revision receipt recovery remains available after another version and stale revision forms are explicit', function () {
    $first = F::obligation($this->owner, $this->f);
    $input = ['idempotency_key' => 'synthetic-http-revision', ...F::input($this->owner, $this->f, ['supersedes_id' => (string) $first->id, 'rate_percent' => '3', 'method_version' => 'Synthetic v2'])];
    $next = $this->postJson('/purification/obligations', $input)->assertCreated();
    $this->get('/purification/obligations/'.$first->id.'/revise')->assertInertia(fn (Assert $p) => $p->where('stale', true));
    $this->postJson('/purification/obligations', $input)->assertCreated()->assertJsonPath('id', $next->json('id'));
    $this->postJson('/purification/obligations', [...$input, 'idempotency_key' => 'synthetic-stale-new-revision'])->assertUnprocessable();
});
test('complete allocation totals and export remain correct beyond visible version and payment pages', function () {
    $o = F::obligation($this->owner, $this->f);
    $p = F::payment($this->owner, $this->f);
    for ($i = 0; $i < 31; $i++) {
        F::allocate($this->owner, $o, $p, '0.01');
    }
    for ($i = 0; $i < 22; $i++) {
        $o = F::obligation($this->owner, $this->f, ['supersedes_id' => (string) $o->id, 'method_version' => 'Synthetic revision '.$i]);
    }
    $this->get('/purification/obligations/'.$o->id)->assertInertia(fn (Assert $i) => $i->has('history.items', 20)->has('allocations', 31)->where('obligation.paid.minor', '31')->where('obligation.outstanding.minor', '169'));
    $this->get('/purification/export')->assertJsonCount(23, 'obligations')->assertJsonCount(31, 'allocations')->assertJsonCount(1, 'payments');
    expect(app(PurificationRead::class)->used($this->owner, (string) $p->id))->toBe('31');
});
test('all purification entry points retain auth verified email and production two-factor requirements', function () {
    auth()->logout();
    $this->get('/purification')->assertRedirect('/login');
    $this->get('/purification/payments/new')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create());
    $this->get('/purification')->assertRedirect('/email/verify');
    $this->app->detectEnvironment(fn () => 'production');
    $this->actingAs($this->owner);
    $this->get('/purification')->assertRedirect('/settings/security');
});
