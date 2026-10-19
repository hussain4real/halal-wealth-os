<?php

use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\PostingService;
use App\Domain\Portfolio\PriceObservationService;
use App\Domain\Portfolio\ValuationFx;
use App\Domain\Reporting\InvestmentValuation;
use App\Domain\Reporting\NetWorthSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\Support\ValuationFixtures as V;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T23:59:59Z'));
    Cache::flush();
    $this->owner = User::factory()->create();
    $this->account = P::account($this->owner, Currency::NGN);
    $this->p = P::link($this->owner, $this->account);
    $this->asset = P::asset($this->owner, ['currency' => 'NGN', 'geography' => 'Synthetic region', 'geography_source' => 'Synthetic classification evidence']);
    $this->opening = P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '1', 'unit_price' => '', 'basis_known' => '1', 'cost_basis' => '90', 'acquired_on' => '2026-10-19'])]);
    P::mode($this->owner, $this->p);
    $this->valuation = app(InvestmentValuation::class);
});
test('opposing native and reporting currency returns use retained exact dated evidence', function () {
    $first = V::price($this->owner, $this->p, $this->asset);
    $r0 = V::fx($this->owner, '2026-10-19', '0.02');
    V::fx($this->owner, '2026-10-19', '0.03', 'transaction');
    $this->travelTo(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
    $last = V::price($this->owner, $this->p, $this->asset, ['unit_price' => '180']);
    $r1 = V::fx($this->owner, '2026-10-20', '0.012');
    $report = $this->valuation->capture($this->owner, Currency::QAR, '2026-10-20T23:59:59Z', (string) $this->account->id, '2026-10-19T23:59:59Z');
    expect($report['performance']['eligible'])->toBeTrue()->and($report['performance']['native_return']['percent'])->toBe('50.0000')
        ->and($report['performance']['base_return']['percent'])->toBe('-10.0000')->and($report['beginning']['total']['minor'])->toBe('240')
        ->and($report['ending']['total']['minor'])->toBe('216');
    $holding = $report['ending']['components'][1];
    expect($holding['price']['id'])->toBe((string) $last->id)->and($holding['fx']['id'])->toBe((string) $r1->id)->and($holding['base_cost']['minor'])->toBe('270')
        ->and($holding['base_unrealized_gain']['minor'])->toBe('-54')->and($report['beginning']['components'][1]['price']['id'])->toBe((string) $first->id)
        ->and($report['beginning']['components'][1]['fx']['id'])->toBe((string) $r0->id);
    expect(bcadd($report['performance']['native_movement_at_start_FX']['minor'], $report['performance']['FX_and_settlement_effect']['minor'], 0))->toBe('-24');
});
test('one missing price gives partial total and complete known allocation denominator without valuing it at zero', function () {
    V::price($this->owner, $this->p, $this->asset);
    $missing = P::asset($this->owner, ['currency' => 'NGN', 'name' => 'Synthetic missing instrument']);
    P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '1', 'unit_price' => '']), P::row($missing, ['quantity' => '2', 'unit_price' => ''])], ['prior_id' => (string) $this->opening->id]);
    $r = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z');
    expect($r['ending']['state'])->toBe('partial')->and($r['ending']['total'])->toBeNull()->and($r['ending']['covered_subtotal']['minor'])->toBe('12000');
    $c = collect($r['ending']['components'])->firstWhere('asset_id', (string) $missing->id);
    expect($c['native'])->toBeNull()->and($c['reason'])->toBe('price_missing')->and($r['ending']['allocation']['denominator_minor'])->toBe('12000');
    foreach ($r['ending']['allocation']['dimensions'] as $groups) {
        expect(array_reduce($groups, fn ($a, $g) => bcadd($a, $g['minor'], 0), '0'))->toBe('12000');
    }
});
test('future corrections cannot erase historical eligible prices or overwrite immutable saved runs', function () {
    $price = V::price($this->owner, $this->p, $this->asset);
    $key = 'synthetic-retained-valuation';
    $saved = $this->valuation->save($this->owner, $key, Currency::NGN, '2026-10-19T23:59:59Z');
    $bytes = $saved->evidence;
    $old = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z');
    $this->travelTo(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
    V::price($this->owner, $this->p, $this->asset, ['supersedes_id' => (string) $price->id, 'unit_price' => '180']);
    $historical = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z');
    expect($historical['ending']['total']['minor'])->toBe('12000')->and($historical['ending']['components'][1]['price']['id'])->toBe((string) $price->id)
        ->and($historical['cache_key'])->not->toBe($old['cache_key']);
    expect($this->valuation->save($this->owner, $key, Currency::NGN, '2026-10-19T23:59:59Z')->evidence)->toBe($bytes);
    expect(fn () => $this->valuation->save($this->owner, $key, Currency::NGN, '2026-10-20T23:59:59Z'))->toThrow(ValidationException::class);
    expect(DB::table('investment_valuation_runs')->count())->toBe(1);
});
test('observed and effective cutoffs both apply with deterministic same-time revisions and inclusive stale boundary', function () {
    $a = V::price($this->owner, $this->p, $this->asset);
    $b = V::price($this->owner, $this->p, $this->asset, ['supersedes_id' => (string) $a->id, 'unit_price' => '125']);
    $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
    V::price($this->owner, $this->p, $this->asset, ['unit_price' => '180', 'effective_at' => '2026-10-19T23:59:59Z', 'observed_at' => '2026-10-28T12:00:00Z']);
    $r = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-26T23:59:59Z');
    expect($r['ending']['total']['minor'])->toBe('12500')->and($r['ending']['components'][1]['price']['id'])->toBe((string) $b->id);
    $stale = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-27T00:00:00Z');
    expect($stale['ending']['total'])->toBeNull()->and($stale['ending']['components'][1]['reason'])->toBe('price_stale');
});
test('raw pre-split prices cannot multiply post-split units and retry preserves original observation receipt', function () {
    $input = V::input($this->p, $this->asset);
    $old = app(PriceObservationService::class)->record($this->owner, 'synthetic-price-retry', $input);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'split']);
    $r = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-20T23:59:59Z');
    expect($r['ending']['components'][1]['reason'])->toBe('price_split_basis_changed')->and($r['ending']['components'][1]['native'])->toBeNull();
    expect(app(PriceObservationService::class)->record($this->owner, 'synthetic-price-retry', $input)->id)->toBe($old->id);
    V::price($this->owner, $this->p, $this->asset, ['unit_price' => '60']);
    expect($this->valuation->capture($this->owner, Currency::NGN, '2026-10-20T23:59:59Z')['ending']['total']['minor'])->toBe('12000');
});
test('known prices do not manufacture unknown basis or historical transaction FX', function () {
    P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '1', 'unit_price' => '', 'basis_known' => '0', 'cost_basis' => ''])], ['prior_id' => (string) $this->opening->id]);
    V::price($this->owner, $this->p, $this->asset);
    V::fx($this->owner, '2026-10-19', '0.02');
    $r = $this->valuation->capture($this->owner, Currency::QAR, '2026-10-19T23:59:59Z');
    expect($r['ending']['components'][1]['converted']['minor'])->toBe('240')->and($r['ending']['components'][1]['native_cost'])->toBeNull()
        ->and($r['ending']['components'][1]['base_cost'])->toBeNull()->and($r['ending']['components'][1]['native_unrealized_gain'])->toBeNull();
});
test('missing transaction FX retains known native cost and market value separately', function () {
    V::price($this->owner, $this->p, $this->asset);
    V::fx($this->owner, '2026-10-19', '0.02');
    $c = $this->valuation->capture($this->owner, Currency::QAR, '2026-10-19T23:59:59Z')['ending']['components'][1];
    expect($c['native_cost']['minor'])->toBe('9000')->and($c['native_unrealized_gain']['minor'])->toBe('3000')->and($c['base_cost'])->toBeNull()->and($c['converted']['minor'])->toBe('240');
});
test('opening acquisition date is never inferred for a foreign cost while same currency and zero cost remain exact', function () {
    P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '1', 'basis_known' => '1', 'cost_basis' => '90'])], ['prior_id' => (string) $this->opening->id]);
    V::price($this->owner, $this->p, $this->asset);
    V::fx($this->owner, '2026-10-19', '0.02', 'transaction');
    V::fx($this->owner, '2026-10-19', '0.02');
    expect($this->valuation->capture($this->owner, Currency::QAR, '2026-10-19T23:59:59Z')['ending']['components'][1]['base_cost'])->toBeNull();
    expect($this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z')['ending']['components'][1]['base_cost']['minor'])->toBe('9000');
});
test('translated acquisitions pool historical rates and allocate exact partial disposal with final residue', function () {
    I::fund($this->owner, $this->account, '1000');
    V::fx($this->owner, '2026-10-19', '0.02', 'transaction');
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '2', 'amount' => '100', 'fee' => '0']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
    V::fx($this->owner, '2026-10-20', '0.03', 'transaction');
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'sell', 'quantity' => '1', 'amount' => '80']);
    V::price($this->owner, $this->p, $this->asset, ['unit_price' => '100']);
    V::fx($this->owner, '2026-10-20', '0.04');
    $r = $this->valuation->capture($this->owner, Currency::QAR, '2026-10-20T23:59:59Z', null, '2026-10-19T23:59:59Z');
    expect($r['ending']['components'][1]['native_cost']['minor'])->toBe('12667')->and($r['ending']['components'][1]['base_cost']['minor'])->toBe('253')
        ->and($r['ending']['financial_results'][0]['results']['realized_gain']['minor'])->toBe('113');
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'sell', 'quantity' => '2', 'amount' => '160']);
    $r = $this->valuation->capture($this->owner, Currency::QAR, '2026-10-20T23:59:59Z', null, '2026-10-19T23:59:59Z');
    expect($r['ending']['financial_results'][0]['results']['pools'][$this->asset->family_reference]['minor'])->toBe('0')
        ->and($r['ending']['financial_results'][0]['results']['realized_gain']['minor'])->toBe('340');
});
test('IPO translated basis comes from payment dates not later allotment FX', function () {
    I::fund($this->owner, $this->account, '1000');
    V::fx($this->owner, '2026-10-19', '0.02', 'transaction');
    $intent = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '150']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_pay', 'amount' => '150', 'subscription_id' => (string) $intent->id]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
    V::fx($this->owner, '2026-10-20', '0.05', 'transaction');
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_allot', 'quantity' => '2', 'amount' => '100', 'subscription_id' => (string) $intent->id]);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_refund', 'amount' => '50', 'subscription_id' => (string) $intent->id]);
    V::price($this->owner, $this->p, $this->asset);
    V::fx($this->owner, '2026-10-20', '0.04');
    $r = $this->valuation->capture($this->owner, Currency::QAR, '2026-10-20T23:59:59Z');
    expect($r['ending']['components'][1]['base_cost']['minor'])->toBe('380')->and($r['ending']['components'][1]['native_cost']['minor'])->toBe('19000')
        ->and($r['ending']['financial_results'][0]['results']['receivable_refund_FX_gain']['minor'])->toBe('150');
});
test('deposit suppresses percentage while a wholly included native transfer remains internal', function () {
    V::price($this->owner, $this->p, $this->asset);
    $other = P::account($this->owner, Currency::NGN);
    I::fund($this->owner, $this->account, '100');
    $this->travelTo(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
    app(PostingService::class)->record($this->owner, (string) Str::uuid(), ['kind' => 'transfer', 'account_id' => (string) $this->account->id, 'destination_account_id' => (string) $other->id, 'amount' => '10', 'effective_date' => '2026-10-20', 'description' => 'Synthetic internal movement']);
    $all = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-20T23:59:59Z', null, '2026-10-19T23:59:59Z');
    expect($all['performance']['eligible'])->toBeTrue()->and($all['performance']['base_return']['percent'])->toBe('0.0000');
    $one = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-20T23:59:59Z', (string) $this->account->id, '2026-10-19T23:59:59Z');
    expect($one['performance']['base_return'])->toBeNull()->and($one['performance']['reasons'])->toContain('external_or_unsupported_cash_flows');
    I::fund($this->owner, $this->account, '25');
    expect($this->valuation->capture($this->owner, Currency::NGN, '2026-10-20T23:59:59Z', null, '2026-10-19T23:59:59Z')['performance']['base_return'])->toBeNull();
});
test('dividends are income already in ending cash and are not counted twice in return', function () {
    V::price($this->owner, $this->p, $this->asset);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'dividend', 'amount' => '15', 'fee' => '1', 'tax' => '2']);
    $r = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-20T23:59:59Z', null, '2026-10-19T23:59:59Z');
    expect($r['ending']['total']['minor'])->toBe('13200')->and($r['performance']['base_return']['percent'])->toBe('10.0000')
        ->and($r['ending']['financial_results'][0]['results']['net_income']['minor'])->toBe('1200');
});
test('aggregate and detailed accounts contribute once with unknown aggregate look-through explicit', function () {
    V::price($this->owner, $this->p, $this->asset);
    $other = P::account($this->owner, Currency::NGN);
    $p = P::link($this->owner, $other);
    I::fund($this->owner, $other, '500');
    P::opening($this->owner, $p, [P::row($this->asset, ['quantity' => '5', 'unit_price' => '100'])]);
    P::mode($this->owner, $p, ['mode' => 'aggregate', 'aggregate_value' => '750', 'aggregate_as_of' => '2026-10-19']);
    $r = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z');
    expect($r['ending']['total']['minor'])->toBe('87000')->and(collect($r['ending']['components'])->where('kind', 'aggregate')->count())->toBe(1);
    $g = collect($r['ending']['allocation']['dimensions']['geography'])->firstWhere('label', 'Unknown / no look-through');
    expect($g['minor'])->toBe('75000');
    I::event($this->owner, $p, $this->asset, ['kind' => 'split']);
    V::price($this->owner, $p, $this->asset);
    expect($this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z')['ending']['total'])->toBeNull();
});
test('owner scope and metadata revisions cannot collide with cached or saved calculations', function () {
    V::price($this->owner, $this->p, $this->asset);
    $a = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z');
    expect($this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z')['cache_key'])->toBe($a['cache_key']);
    $b = $this->valuation->capture(User::factory()->create(), Currency::NGN, '2026-10-19T23:59:59Z');
    expect($b['cache_key'])->not->toBe($a['cache_key'])->and($b['ending']['components'])->toBe([]);
    expect(fn () => $this->valuation->capture(User::factory()->create(), Currency::NGN, '2026-10-19T23:59:59Z', (string) $this->account->id))->toThrow(NotFoundHttpException::class);
    $this->account->name = 'Synthetic renamed account';
    $this->account->save();
    $c = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z');
    expect($c['cache_key'])->not->toBe($a['cache_key'])->and($c['ending']['components'][0]['account'])->toBe('Synthetic renamed account');
});
test('all holdings beyond presentation pages contribute and reads never post or alter financial facts', function () {
    $rows = [];
    for ($i = 0; $i < 23; $i++) {
        $asset = P::asset($this->owner, ['currency' => 'NGN', 'name' => 'Synthetic holding '.$i]);
        $rows[] = P::row($asset, ['quantity' => '1', 'unit_price' => '2']);
    }
    P::opening($this->owner, $this->p, $rows, ['prior_id' => (string) $this->opening->id]);
    $before = [DB::table('journal_entries')->count(), DB::table('investment_events')->count()];
    $r = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z');
    expect($r['ending']['total']['minor'])->toBe('4600')->and($r['ending']['component_count'])->toBe(24)
        ->and([DB::table('journal_entries')->count(), DB::table('investment_events')->count()])->toBe($before);
});
test('price input rejects spoofing cross owner wrong currency unconfirmed units and branched corrections', function () {
    $service = app(PriceObservationService::class);
    foreach ([['owner_id' => '5'], ['unit_basis_confirmed' => ''], ['unit_price' => 1.25], ['unit_price' => '-1']] as $bad) {
        expect(fn () => V::price($this->owner, $this->p, $this->asset, $bad))->toThrow(ValidationException::class);
    }
    expect(fn () => V::price(User::factory()->create(), $this->p, $this->asset))->toThrow(NotFoundHttpException::class);
    $foreign = P::asset($this->owner);
    expect(fn () => V::price($this->owner, $this->p, $foreign))->toThrow(ValidationException::class);
    $a = V::price($this->owner, $this->p, $this->asset);
    V::price($this->owner, $this->p, $this->asset, ['supersedes_id' => (string) $a->id]);
    expect(fn () => V::price($this->owner, $this->p, $this->asset, ['supersedes_id' => (string) $a->id]))->toThrow(ValidationException::class);
});

test('transaction FX observed after an intraday report cutoff never leaks into opening cost', function () {
    V::price($this->owner, $this->p, $this->asset, ['effective_at' => '2026-10-19T09:00:00Z', 'observed_at' => '2026-10-19T09:00:00Z']);
    app(FxObservationService::class)->record($this->owner, 'synthetic-morning-report-FX', ['base_currency' => 'NGN', 'quote_currency' => 'QAR', 'value' => '0.02', 'source' => 'Synthetic morning quote', 'effective_at' => '2026-10-19T09:00:00Z', 'observed_at' => '2026-10-19T09:00:00Z']);
    V::fx($this->owner, '2026-10-19', '0.05', 'transaction');
    $r = $this->valuation->capture($this->owner, Currency::QAR, '2026-10-19T12:00:00Z');
    expect($r['ending']['components'][1]['converted']['minor'])->toBe('240')->and($r['ending']['components'][1]['base_cost'])->toBeNull();
});
test('accounts with identical display names retain distinct allocation identities', function () {
    V::price($this->owner, $this->p, $this->asset);
    $other = P::account($this->owner, Currency::NGN);
    I::fund($this->owner, $other, '10');
    $r = $this->valuation->capture($this->owner, Currency::NGN, '2026-10-19T23:59:59Z');
    expect($r['ending']['allocation']['dimensions']['account'])->toHaveCount(2);
    $groups = $r['ending']['allocation']['dimensions']['account'];
    expect($groups[0]['label'])->not->toBe($groups[1]['label']);
});
test('shared net worth and investment valuation settle the same component FX rounding ties', function () {
    $asset = P::asset($this->owner, ['currency' => 'NGN']);
    P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '1', 'unit_price' => '0.01']), P::row($asset, ['quantity' => '1', 'unit_price' => '0.01'])], ['prior_id' => (string) $this->opening->id]);
    V::fx($this->owner, '2026-10-19', '0.5');
    $r = $this->valuation->capture($this->owner, Currency::QAR, '2026-10-19T23:59:59Z');
    $net = app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-19T23:59:59Z');
    expect($r['ending']['total']['minor'])->toBe('2')->and($net['total']['minor'])->toBe('2')->and($r['ending']['net_worth_snapshot']['total']['minor'])->toBe('2');
});

test('price unit confirmation rejects a corporate action committed after the form context was captured', function () {
    $input = V::input($this->p, $this->asset);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'split']);
    expect(fn () => app(PriceObservationService::class)->record($this->owner, 'synthetic-stale-unit-confirmation', $input))->toThrow(ValidationException::class);
    expect(DB::table('portfolio_prices')->count())->toBe(0);
});

test('an owned internal accounting account is rejected as an unsupported valuation scope', function () {
    I::fund($this->owner, $this->account, '10');
    $internal = DB::table('accounts')->where('owner_id', $this->owner->id)->whereNotNull('system_role')->first();
    expect($internal)->not->toBeNull();
    expect(fn () => $this->valuation->capture($this->owner, Currency::QAR, '2026-10-19T23:59:59Z', (string) $internal->id))->toThrow(ValidationException::class);
});

test('detailed net worth preserves missing and stale FX reasons while foreign zero has explicit identity metadata', function () {
    V::price($this->owner, $this->p, $this->asset);
    $net = app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-19T23:59:59Z');
    expect($net['components'][0]['state'])->toBe('missing')->and($net['components'][0]['conversion_reason'])->toBe('fx_missing');
    V::fx($this->owner, '2026-10-19', '0.02');
    $this->travelTo(CarbonImmutable::parse('2026-10-27T12:00:00Z'));
    V::price($this->owner, $this->p, $this->asset);
    $net = app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-27T12:00:00Z');
    expect($net['components'][0]['state'])->toBe('stale')->and($net['components'][0]['conversion_reason'])->toBe('fx_stale');
    expect(app(ValuationFx::class)->convert($this->owner, '0', Currency::NGN, Currency::QAR, '2026-10-27T12:00:00Z', 'reporting')['conversion'])->toBe('zero_amount_identity');
});
