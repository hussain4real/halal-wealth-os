<?php

use App\Domain\Reporting\InvestmentValuation;
use App\Domain\Reporting\NetWorthSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\Support\ValuationFixtures as V;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->account = P::account($this->owner);
    $this->p = P::link($this->owner, $this->account);
    $this->asset = P::asset($this->owner);
    P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '1.234567890123', 'unit_price' => '', 'basis_known' => '1', 'cost_basis' => '1.25'])]);
    P::mode($this->owner, $this->p);
    $this->input = ['base_currency' => 'QAR', 'cutoff' => '2026-10-19T12:00:00Z', 'start' => '', 'account_id' => (string) $this->account->id];
});
test('authenticated manual price entry retains exact text and recovers a repeated request', function () {
    $input = V::input($this->p, $this->asset, ['unit_price' => '2.000000000001']);
    unset($input['portfolio_account_id']);
    $url = '/portfolio/'.$this->p->id.'/prices';
    $r = $this->postJson($url, ['idempotency_key' => 'synthetic-http-price', ...$input])->assertCreated();
    expect($this->postJson($url, ['idempotency_key' => 'synthetic-http-price', ...$input])->assertCreated()->json('id'))->toBe($r->json('id'));
    $this->get($url)->assertInertia(fn (Assert $p) => $p->component('portfolio/Prices')->where('prices.0.unit_price', '2.000000000001')->where('prices.0.version', '1'));
    $this->assertDatabaseCount('portfolio_prices', 1);
});
test('calculation and immutable receipt expose exact provenance with private complete export', function () {
    V::price($this->owner, $this->p, $this->asset, ['unit_price' => '2.000000000001']);
    $this->get('/valuations?'.http_build_query($this->input))->assertInertia(fn (Assert $p) => $p->component('portfolio/Valuation')->where('result.ending.total.minor', '247')->where('result.ending.components.1.price.value', '2.000000000001'));
    $row = $this->postJson('/valuations', ['idempotency_key' => 'synthetic-http-save', ...$this->input])->assertCreated();
    $this->get($row->json('url'))->assertInertia(fn (Assert $p) => $p->component('portfolio/ValuationRun')->where('evidence.ending.total.minor', '247'));
    $this->get($row->json('url').'/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('ending.components.1.quantity', '1.234567890123')->assertJsonPath('ending.components.1.price.value', '2.000000000001');
});
test('new report identity cannot collide with legacy net-worth request receipts', function () {
    V::price($this->owner, $this->p, $this->asset);
    app(NetWorthSnapshot::class)->save($this->owner, 'synthetic-shared-report-key', Currency::QAR, $this->input['cutoff']);
    $this->postJson('/valuations', ['idempotency_key' => 'synthetic-shared-report-key', ...$this->input])->assertCreated();
    $this->assertDatabaseCount('valuation_reports', 1);
    $this->assertDatabaseCount('investment_valuation_runs', 1);
});
test('all foreign nested observation account saved-run and export paths are isolated', function () {
    $saved = app(InvestmentValuation::class)->save($this->owner, 'synthetic-owner-private-run', Currency::QAR, $this->input['cutoff']);
    $foreign = User::factory()->create();
    $this->actingAs($foreign);
    foreach (['/portfolio/'.$this->p->id.'/prices', '/valuations/'.$saved->id, '/valuations/'.$saved->id.'/export', '/valuations/export?'.http_build_query($this->input)] as $url) {
        $this->getJson($url)->assertNotFound();
    }
    $this->postJson('/valuations', ['idempotency_key' => 'synthetic-foreign-save', ...$this->input])->assertNotFound();
});
test('HTTP price and report writes reject numeric amounts spoofing invalid timestamps and changed retries', function () {
    $input = V::input($this->p, $this->asset);
    unset($input['portfolio_account_id']);
    $url = '/portfolio/'.$this->p->id.'/prices';
    $this->postJson($url, ['idempotency_key' => 'synthetic-bad-price-number', ...$input, 'unit_price' => 2.25])->assertUnprocessable()->assertJsonValidationErrors('unit_price');
    $this->postJson($url, ['idempotency_key' => 'synthetic-bad-price-owner', ...$input, 'owner_id' => '5'])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    $this->postJson('/valuations', ['idempotency_key' => 'synthetic-bad-cutoff', ...$this->input, 'cutoff' => '2026-10-19'])->assertUnprocessable()->assertJsonValidationErrors('cutoff');
    $this->postJson('/valuations', ['idempotency_key' => 'synthetic-http-recovery', ...$this->input])->assertCreated();
    $this->postJson('/valuations', ['idempotency_key' => 'synthetic-http-recovery', ...$this->input, 'base_currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->getJson('/valuations?base_currency[]=QAR')->assertUnprocessable();
});
test('bounded selected component page preserves complete totals allocations and export', function () {
    $prior = DB::table('portfolio_openings')->where('portfolio_account_id', $this->p->id)->sole();
    $rows = [];
    for ($i = 0; $i < 23; $i++) {
        $a = P::asset($this->owner, ['name' => 'Synthetic paged asset '.$i]);
        $rows[] = P::row($a, ['quantity' => '1', 'unit_price' => '3']);
    }
    P::opening($this->owner, $this->p, $rows, ['prior_id' => (string) $prior->id]);
    $query = http_build_query($this->input);
    $this->get('/valuations?'.$query)->assertInertia(fn (Assert $p) => $p->has('result.ending.components', 20)->where('result.ending.total.minor', '6900')->where('next', '20'));
    $this->get('/valuations/export?'.$query)->assertOk()->assertJsonCount(24, 'ending.components')->assertJsonPath('ending.total.minor', '6900');
});
test('opening acquisition dates and sourced geography survive HTTP versioned input and read', function () {
    $asset = P::asset($this->owner, ['geography' => 'Synthetic sourced region', 'geography_source' => 'Synthetic classification']);
    $this->get('/assets/'.$asset->id)->assertInertia(fn (Assert $p) => $p->where('asset.geography', 'Synthetic sourced region')->where('asset.geography_source', 'Synthetic classification'));
    $this->postJson('/assets', ['idempotency_key' => 'synthetic-unsourced-geography', 'prior_id' => '', 'name' => 'Synthetic', 'kind' => 'equity', 'currency' => 'QAR', 'source' => 'Synthetic', 'reason' => 'Synthetic', 'geography' => 'Synthetic region'])->assertUnprocessable()->assertJsonValidationErrors('geography');
});

test('HTTP price context rejects tampering and another-tab split before a fresh confirmed admission', function () {
    $url = '/portfolio/'.$this->p->id.'/prices';
    $input = V::input($this->p, $this->asset);
    unset($input['portfolio_account_id']);
    $this->get($url)->assertInertia(fn (Assert $p) => $p->where('context', $input['context']));
    $this->postJson($url, ['idempotency_key' => 'synthetic-tampered-context', ...$input, 'context' => str_repeat('0', 64)])->assertUnprocessable()->assertJsonValidationErrors('unit_basis_confirmed');
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'split']);
    $this->postJson($url, ['idempotency_key' => 'synthetic-stale-http-context', ...$input])->assertUnprocessable()->assertJsonValidationErrors('unit_basis_confirmed');
    $this->assertDatabaseCount('portfolio_prices', 0);
    $fresh = V::input($this->p, $this->asset, ['unit_price' => '60']);
    unset($fresh['portfolio_account_id']);
    $this->postJson($url, ['idempotency_key' => 'synthetic-fresh-http-context', ...$fresh])->assertCreated();
    $this->assertDatabaseCount('portfolio_prices', 1);
});
test('HTTP calculation save and export reject an owned internal accounting scope', function () {
    I::fund($this->owner, $this->account, '10');
    $internal = DB::table('accounts')->where('owner_id', $this->owner->id)->whereNotNull('system_role')->first();
    $input = [...$this->input, 'account_id' => (string) $internal->id];
    $this->getJson('/valuations?'.http_build_query($input))->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->getJson('/valuations/export?'.http_build_query($input))->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->postJson('/valuations', ['idempotency_key' => 'synthetic-internal-http-scope', ...$input])->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->assertDatabaseCount('investment_valuation_runs', 0);
});
