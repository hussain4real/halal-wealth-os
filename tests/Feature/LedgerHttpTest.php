<?php

use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\FxService;
use App\Domain\Ledger\ReconciliationService;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FxFixtures as Fx;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->source = Fx::account($this->owner);
    $this->destination = Fx::account($this->owner, Currency::NGN);
    $this->input = Fx::input($this->source, $this->destination);
    $this->cutoff = now('UTC')->format('Y-m-d\TH:i:s\Z');
});

test('ledger pages retain private verified access and render all new Inertia screens', function () {
    foreach (['/fx', '/fx/new', '/rates', '/reports', '/reconciliations'] as $path) {
        $this->get($path)->assertRedirect('/login');
    }
    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->get('/reports')->assertRedirect('/email/verify');
    $this->actingAs($this->owner);
    foreach (['/fx' => 'fx/Index', '/fx/new' => 'fx/Create', '/rates' => 'ledger/Rates', '/reports' => 'ledger/Reports', '/reconciliations' => 'ledger/Reconciliations'] as $path => $component) {
        $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }
});

test('JSON FX delivery recovers its durable reference and native string cash without reposting', function () {
    $this->actingAs($this->owner);
    $payload = [...$this->input, 'idempotency_key' => 'http-fx-lost-response'];
    $first = $this->postJson('/fx', $payload)->assertCreated();
    $this->postJson('/fx', $payload)->assertCreated()->assertExactJson($first->json());
    $id = $first->json('id');
    $this->get('/fx/'.$id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('fx/Show')->where('operation.source.money.minor', '7325')->where('operation.destination.money.minor', '2966625')->has('operation.journals', 3));
    $journal = DB::table('fx_operation_journals')->where('operation_id', $id)->where('role', 'source')->value('journal_id');
    $this->get('/transactions/'.$journal.'/correct')->assertRedirect('/fx/'.$id);
    $this->postJson('/transactions/'.$journal.'/reverse', ['idempotency_key' => 'http-partial-reverse', 'reason' => 'Synthetic partial attempt'])->assertUnprocessable();
    $this->postJson('/transactions/'.$journal.'/correct', [...$this->input, 'idempotency_key' => 'http-partial-correct'])->assertUnprocessable();
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('journal_entries', 3);
});

test('foreign financial identities remain inaccessible before cached recovery even for an administrator', function (bool $admin) {
    $operation = app(FxService::class)->record($this->owner, 'owner-fx-known-key', $this->input);
    $rate = app(FxObservationService::class)->record($this->owner, 'owner-rate-known-key', Fx::quote());
    $report = app(FinancialSnapshot::class)->save($this->owner, 'owner-report-known-key', Currency::QAR, $this->cutoff);
    $session = app(ReconciliationService::class)->record($this->owner, 'owner-rec-known-key', (string) $this->source->id, '17.35', $this->cutoff);
    $other = User::factory()->create(['is_admin' => $admin]);
    $this->actingAs($other);
    foreach (['/fx/'.$operation->id, '/fx/'.$operation->id.'/correct', '/rates?supersedes='.$rate->id, '/reports/'.$report->id, '/reconciliations/'.$session->id, '/reconciliations?supersedes='.$session->id] as $url) {
        $this->get($url)->assertNotFound();
    }
    $this->postJson('/fx', [...$this->input, 'idempotency_key' => 'owner-fx-known-key'])->assertNotFound();
    $this->postJson('/fx/'.$operation->id.'/reverse', ['idempotency_key' => 'foreign-reverse-key', 'reason' => 'Synthetic foreign attempt'])->assertNotFound();
    $this->postJson('/fx/'.$operation->id.'/correct', [...$this->input, 'idempotency_key' => 'foreign-correct-key'])->assertNotFound();
    $this->postJson('/rates', [...Fx::quote(), 'idempotency_key' => 'owner-rate-known-key', 'supersedes_id' => (string) $rate->id, 'correction_reason' => 'Synthetic foreign attempt'])->assertNotFound();
    $this->postJson('/reconciliations', ['idempotency_key' => 'owner-rec-known-key', 'account_id' => (string) $this->source->id, 'statement_amount' => '17.35', 'cutoff' => $this->cutoff])->assertNotFound();
    $this->postJson('/reconciliations/'.$session->id.'/adjust', ['idempotency_key' => 'foreign-adjust-key', 'offset' => 'equity', 'reason' => 'Synthetic foreign attempt'])->assertNotFound();
    $this->get('/reports')->assertInertia(fn (Assert $page) => $page->has('evidence.components', 0)->has('reports', 0));
    $this->assertDatabaseCount('fx_operations', 1);
    $this->assertDatabaseCount('reconciliation_adjustments', 0);
})->with([false, true]);

test('malformed and overflowing request identities are controlled without database exceptions', function (string $suffix) {
    $this->actingAs($this->owner);
    foreach (['/fx/'.$suffix, '/reports/'.$suffix, '/reconciliations/'.$suffix, '/rates?supersedes='.$suffix, '/reconciliations?supersedes='.$suffix] as $url) {
        $this->get($url)->assertNotFound();
    }
    $this->postJson('/fx', [...$this->input, 'source_account_id' => $suffix, 'idempotency_key' => 'invalid-account-id'])->assertNotFound();
})->with(['9223372036854775808', '9999999999999999999', '0001']);

test('array revision query input is rejected without casting errors', function () {
    $this->actingAs($this->owner)->get('/rates?supersedes[]=x')->assertNotFound();
    $this->get('/reconciliations?supersedes[]=x')->assertNotFound();
});

test('submitted FX money and system fields cannot alter trusted currencies or bypass strict decimal input', function (string $field, mixed $value) {
    $this->actingAs($this->owner)->postJson('/fx', [...$this->input, $field => $value, 'idempotency_key' => 'strict-fx-input-key'])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('fx_operations', 0);
})->with([['source_currency', 'USD'], ['owner_id', '42'], ['source_amount', 73.25], ['source_amount', '1e3'], ['source_amount', '1.001'], ['rate_value', '0'], ['fee_amount', '-0.01']]);

test('saved balance and reconciliation HTTP retries retain immutable evidence and explicit attributed correction', function () {
    $this->actingAs($this->owner);
    $report = ['idempotency_key' => 'http-report-recovery', 'base_currency' => 'QAR', 'cutoff' => $this->cutoff];
    $saved = $this->postJson('/reports', $report)->assertCreated();
    $this->postJson('/reports', $report)->assertCreated()->assertExactJson($saved->json());
    $this->get($saved->json('url'))->assertInertia(fn (Assert $page) => $page->component('ledger/Reports')->where('saved.id', $saved->json('id'))->where('evidence.state', 'partial'));
    $comparison = $this->postJson('/reconciliations', ['idempotency_key' => 'http-compare-recovery', 'account_id' => (string) $this->source->id, 'statement_amount' => '17.35', 'cutoff' => $this->cutoff])->assertCreated();
    $this->get($comparison->json('url'))->assertInertia(fn (Assert $page) => $page->component('ledger/Reconciliation')->where('session.status', 'difference')->where('session.difference_minor', '1735'));
    $url = $comparison->json('url').'/adjust';
    $this->postJson($url, ['idempotency_key' => 'http-adjust-recovery', 'offset' => 'income', 'reason' => 'Synthetic wrong classification'])->assertUnprocessable()->assertJsonValidationErrors('offset');
    $payload = ['idempotency_key' => 'http-adjust-recovery', 'offset' => 'equity', 'reason' => 'Synthetic statement reference'];
    $adjusted = $this->postJson($url, $payload)->assertCreated();
    $this->postJson($url, $payload)->assertCreated()->assertExactJson($adjusted->json());
    $this->get($adjusted->json('url'))->assertInertia(fn (Assert $page) => $page->where('session.status', 'matched')->where('session.difference_minor', '0')->where('adjustment.reason', 'Synthetic statement reference'));
    $this->assertDatabaseCount('journal_entries', 1);
});
