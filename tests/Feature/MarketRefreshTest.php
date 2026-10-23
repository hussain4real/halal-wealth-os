<?php

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use App\Domain\Integrations\TwelveData;
use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Portfolio\PositionPricing;
use App\Domain\Portfolio\PriceObservationService;
use App\Domain\Portfolio\ValuationFx;
use App\Domain\Reporting\InvestmentValuation;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FxFixtures;
use Tests\Support\MarketFixtures as M;
use Tests\Support\PortfolioFixtures;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-23T12:00:00Z'));
    $this->owner = User::factory()->create();
    config(['market_data.mode' => 'mock']);
    Http::preventStrayRequests();
    $this->refresh = app(MarketRefresh::class);
});

test('mock price refresh retains exact source date once across requests and new day jobs', function () {
    $p = M::price($this->owner);
    Http::fake(['api.twelvedata.com/time_series*' => Http::response(M::priceBody('2026-10-22'))]);
    $first = $this->refresh->refresh($p['feed'], '2026-10-23');
    expect($first['status'])->toBe('accepted');
    $row = DB::table('portfolio_prices')->first();
    expect((string) $row->unit_price)->toBe('12.123456789012')->and($row->observed_at)->toBeNull()->and($row->effective_at)->toBeNull()->and($row->source_date)->toBe('2026-10-22');
    $this->refresh->refresh($p['feed'], '2026-10-23');
    Http::assertSentCount(1);
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));
    $this->refresh->refresh($p['feed'], '2026-10-24');
    Http::assertSentCount(2);
    $this->assertDatabaseCount('portfolio_prices', 1);
    $this->assertDatabaseCount('market_admissions', 1);
    expect(DB::table('portfolio_prices')->first()->received_at)->toBe($row->received_at);
    Http::assertSent(fn ($r) => $r['adjust'] === 'none' && $r['mic_code'] === 'XNAS' && $r['apikey'] === 'synthetic-mock-only');
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('investment_events', 0);
});

test('FX provider admission is reporting only with exact source timestamp and genuine availability', function () {
    $m = $this->refresh->map($this->owner, 'fx-mapping', M::fxInput());
    Http::fake(['api.twelvedata.com/exchange_rate*' => Http::response(M::fxBody(value: '3.640000000001'))]);
    expect($this->refresh->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23')['status'])->toBe('accepted');
    $row = DB::table('fx_observations')->first();
    expect((string) $row->value)->toBe('3.640000000001')->and($row->purpose)->toBe('reporting')->and($row->time_policy)->toBe('provider-instant-v1');
    $r = DB::table('market_admissions')->first();
    expect($this->refresh->admit($this->owner, (string) $m->id, (string) $r->result_id)->id)->toBe($r->id);
    $this->assertDatabaseCount('fx_observations', 1);
});

test('wrong currency and future observation fail attributably without replacing prior price', function () {
    $p = M::price($this->owner);
    Http::fake(['api.twelvedata.com/time_series*' => Http::sequence()->push(M::priceBody('2026-10-22'))->push(M::priceBody('2026-10-23', meta: ['currency' => 'EUR']))]);
    $this->refresh->refresh($p['feed'], '2026-10-23');
    $old = DB::table('portfolio_prices')->first();
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));
    $bad = $this->refresh->refresh($p['feed'], '2026-10-24');
    expect($bad['status'])->toBe('rejected')->and($bad['code'])->toBe('wrong_currency');
    expect(DB::table('portfolio_prices')->first())->toEqual($old);
    expect($this->refresh->refresh($p['feed'], '2026-10-24')['status'])->toBe('blocked');
    $this->assertDatabaseCount('portfolio_prices', 1);
});

test('bounded provider timeout retries preserve last observation and charged attempts', function () {
    $this->refresh->map($this->owner, 'fx-mapping', M::fxInput());
    Http::fake(['api.twelvedata.com/*' => Http::failedConnection()]);
    $feed = MarketFeed::fx('USD', 'QAR');
    expect($this->refresh->refresh($feed, '2026-10-23'))->toMatchArray(['status' => 'retryable', 'code' => 'timeout']);
    expect($this->refresh->refresh($feed, '2026-10-23')['status'])->toBe('deferred');
    $this->travel(60)->seconds();
    expect($this->refresh->refresh($feed, '2026-10-23')['status'])->toBe('retryable');
    $this->travel(300)->seconds();
    expect($this->refresh->refresh($feed, '2026-10-23')['status'])->toBe('retryable');
    $this->travel(300)->seconds();
    expect($this->refresh->refresh($feed, '2026-10-23'))->toMatchArray(['status' => 'blocked', 'code' => 'attempts_exhausted']);
    $this->assertDatabaseCount('market_attempts', 3);
    $this->assertDatabaseCount('fx_observations', 0);
});

test('date-only price cannot enter a historical cutoff before its actual receipt and remains source stale', function () {
    $p = M::price($this->owner);
    Http::fake(['api.twelvedata.com/*' => Http::response(M::priceBody('2026-10-10'))]);
    $this->refresh->refresh($p['feed'], '2026-10-23');
    $position = ['kind' => $p['asset']->kind, 'asset_family' => $p['asset']->family_reference, 'currency' => 'USD', 'quantity' => '1', 'cost_basis' => null, 'unit_price' => null];
    $reader = app(PositionPricing::class);
    expect($reader->value($this->owner, (string) $p['account']->id, $position, null, '2026-10-22T12:00:00Z')['reason'])->toBe('price_missing');
    $view = $reader->value($this->owner, (string) $p['account']->id, $position, null, '2026-10-23T12:00:00Z');
    expect($view['reason'])->toBe('price_stale')->and($view['price']['provider_time']['source_date'])->toBe('2026-10-10')->and($view['price']['observed_at'])->toBeNull();
});

test('disabled production-style mode never sends provider HTTP or loses prior mapping receipts', function () {
    $this->refresh->map($this->owner, 'fx-mapping', M::fxInput());
    config(['market_data.mode' => 'disabled']);
    Http::fake();
    expect($this->refresh->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23'))->toBe(['status' => 'disabled']);
    Http::assertNothingSent();
    $this->assertDatabaseCount('market_attempts', 0);
});

test('rate limiting defers both price and FX feeds under the one shared provider cooldown', function () {
    $p = M::price($this->owner);
    $this->refresh->map($this->owner, 'fx-mapping', M::fxInput());
    Http::fake(['api.twelvedata.com/*' => Http::sequence()->push('', 429, ['Retry-After' => '120'])->push(M::fxBody())]);
    expect($this->refresh->refresh($p['feed'], '2026-10-23'))->toMatchArray(['status' => 'retryable', 'code' => 'rate_limited']);
    expect($this->refresh->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23'))->toMatchArray(['status' => 'deferred', 'code' => 'provider_backoff']);
    Http::assertSentCount(1);
    $this->travel(120)->seconds();
    expect($this->refresh->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23')['status'])->toBe('accepted');
    Http::assertSentCount(2);
});

test('a mapping can be disabled after its reviewed account context changes and exact receipts recover', function () {
    $p = M::price($this->owner);
    Http::fake(['api.twelvedata.com/*' => Http::response(M::priceBody('2026-10-22'))]);
    $run = $this->refresh->refresh($p['feed'], '2026-10-23');
    $receipt = DB::table('market_admissions')->first();
    PortfolioFixtures::opening($this->owner, $p['account'], [PortfolioFixtures::row($p['asset'])]);
    $disabled = $this->refresh->map($this->owner, 'disable-after-history-change', ['enabled' => '0', 'supersedes_id' => (string) $p['mapping']->id]);
    expect($disabled->enabled)->toBeFalse();
    config(['market_data.mode' => 'disabled']);
    expect($this->refresh->admit($this->owner, (string) $p['mapping']->id, $run['result_id'])->id)->toBe($receipt->id);
    $this->assertDatabaseCount('portfolio_prices', 1);
});

test('all historical reporting readers exclude a provider rate until it was actually received', function () {
    $account = FxFixtures::account($this->owner, Currency::USD);
    $this->refresh->map($this->owner, 'fx-mapping', M::fxInput());
    Http::fake(['api.twelvedata.com/*' => Http::response(M::fxBody(timestamp: (new DateTimeImmutable('2026-10-22T00:00:00Z'))->getTimestamp()))]);
    $this->refresh->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23');
    $financial = app(FinancialSnapshot::class);
    $before = $financial->capture($this->owner, Currency::QAR, '2026-10-22T12:00:00Z');
    $after = $financial->capture($this->owner, Currency::QAR, '2026-10-23T12:00:00Z');
    expect($before['components'][0]['rate'])->toBeNull()->and($after['components'][0]['rate']['provider_time']['received_at'])->toBe('2026-10-23T12:00:00Z');
    $read = app(ValuationFx::class)->convert($this->owner, '100', Currency::USD, Currency::QAR, '2026-10-22T12:00:00Z', 'reporting');
    expect($read['reason'])->toBe('fx_missing');
});

test('daily provider prices and manual revision pages return explicit date evidence instead of failing on null instants', function () {
    $p = M::price($this->owner);
    Http::fake(['api.twelvedata.com/*' => Http::response(M::priceBody('2026-10-22'))]);
    $this->refresh->refresh($p['feed'], '2026-10-23');
    $price = DB::table('portfolio_prices')->first();
    $this->actingAs($this->owner);
    foreach (['', '?revise='.$price->id] as $query) {
        $this->get('/portfolio/'.$p['account']->id.'/prices'.$query)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portfolio/Prices')->where('prices.0.observed_at', null)->where('prices.0.provider_time.source_date', '2026-10-22'));
    }
});

test('a late first response cannot suppress the remaining durable retry budget', function () {
    $this->refresh->map($this->owner, 'fx-mapping', M::fxInput());
    $calls = 0;
    Http::fake(function () use (&$calls) {
        if ($calls++ === 0) {
            $this->travel(21)->seconds();
        }

        return Http::response(M::fxBody());
    });
    $feed = MarketFeed::fx('USD', 'QAR');
    expect($this->refresh->refresh($feed, '2026-10-23'))->toMatchArray(['status' => 'late', 'code' => 'late_ignored']);
    $this->assertDatabaseCount('fx_observations', 0);
    $this->travel(300)->seconds();
    expect($this->refresh->refresh($feed, '2026-10-23')['status'])->toBe('accepted');
    $this->assertDatabaseCount('market_attempts', 2);
    $this->assertDatabaseCount('fx_observations', 1);
});

test('changed retained history rejects owned admission while retaining safe attributable shared evidence', function () {
    $p = M::price($this->owner);
    PortfolioFixtures::opening($this->owner, $p['account'], [PortfolioFixtures::row($p['asset'])]);
    Http::fake(['api.twelvedata.com/*' => Http::response(M::priceBody('2026-10-22'))]);
    $run = $this->refresh->refresh($p['feed'], '2026-10-23');
    expect($run['status'])->toBe('accepted');
    $status = $this->refresh->status($this->owner);
    expect($status['mappings'][0]['run']['status'])->toBe('accepted')->and($status['mappings'][0]['admission'])->toBe(['outcome' => 'rejected', 'code' => 'mapping_or_capacity_changed']);
    $receipt = DB::table('market_admissions')->first();
    expect($this->refresh->admit($this->owner, (string) $p['mapping']->id, $run['result_id'])->id)->toBe($receipt->id);
    $this->assertDatabaseCount('portfolio_prices', 0);
    $this->assertDatabaseCount('market_admissions', 1);
    $this->refresh->refresh($p['feed'], '2026-10-23');
    Http::assertSentCount(1);
});

test('complete export headroom rolls back observation and receipt but committed sample remains recoverable', function () {
    $mapping = $this->refresh->map($this->owner, 'headroom-fx-map', M::fxInput());
    $limited = new MarketRefresh(app(TwelveData::class), app(TwelveData::class), 100);
    Http::fake(['api.twelvedata.com/*' => Http::response(M::fxBody())]);
    $run = $limited->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23');
    expect($run['status'])->toBe('accepted');
    $this->assertDatabaseCount('fx_observations', 0);
    $this->assertDatabaseCount('market_admissions', 0);
    $this->assertDatabaseCount('market_results', 1);
    expect(fn () => $limited->map($this->owner, 'headroom-reject-map', M::fxInput('EUR', 'QAR')))->toThrow(ValidationException::class);
    $disabled = $limited->map($this->owner, 'headroom-disable-map', ['enabled' => '0', 'supersedes_id' => (string) $mapping->id]);
    expect($disabled->enabled)->toBeFalse();
    $this->assertDatabaseCount('market_mappings', 2);
    // A disabled new admission cannot consume the saved result; its rejection is retained.
    expect($this->refresh->admit($this->owner, (string) $disabled->id, $run['result_id'])->outcome)->toBe('rejected');
    $this->assertDatabaseCount('fx_observations', 0);
    $replacement = $this->refresh->map($this->owner, 'headroom-reenable-map', [...M::fxInput(), 'supersedes_id' => (string) $disabled->id]);
    expect($this->refresh->admit($this->owner, (string) $replacement->id, $run['result_id'])->outcome)->toBe('accepted');
    $this->assertDatabaseCount('fx_observations', 1);
    Http::assertSentCount(1);
});

test('mapping versions reject duplicate current targets obsolete retries and incompatible owned instruments', function () {
    $p = M::price($this->owner);
    expect(fn () => $this->refresh->map($this->owner, 'mapping-duplicate-target', $p['input']))->toThrow(ValidationException::class);
    $disabled = $this->refresh->map($this->owner, 'mapping-disable-prior', ['enabled' => '0', 'supersedes_id' => (string) $p['mapping']->id]);
    expect(fn () => $this->refresh->map($this->owner, 'mapping-obsolete-prior', [...$p['input'], 'supersedes_id' => (string) $p['mapping']->id]))->toThrow(ValidationException::class);
    DB::table('accounts')->where('id', $p['account']->account_id)->update(['archived_at' => now()]);
    expect(fn () => $this->refresh->map($this->owner, 'mapping-archived-target', [...$p['input'], 'supersedes_id' => (string) $disabled->id]))->toThrow(ValidationException::class);
    expect(fn () => $this->refresh->map($this->owner, 'mapping-invalid-toggle', [...M::fxInput(), 'enabled' => 'yes']))->toThrow(ValidationException::class);
    expect(fn () => $this->refresh->map($this->owner, 'mapping-invalid-kind', [...M::fxInput(), 'kind' => 'guess']))->toThrow(ValidationException::class);
    expect(fn () => $this->refresh->map($this->owner, 'mapping-nontext-value', [...M::fxInput(), 'base' => []]))->toThrow(ValidationException::class);
});

test('an old uncommitted window cannot fetch and excessive provider backoff stops all new feeds', function () {
    Http::fake(['api.twelvedata.com/*' => Http::response('', 429, ['Retry-After' => '604801'])]);
    expect($this->refresh->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-22'))->toMatchArray(['status' => 'blocked', 'code' => 'window_expired']);
    Http::assertNothingSent();
    expect($this->refresh->refresh(MarketFeed::fx('USD', 'QAR'), '2026-10-23'))->toMatchArray(['status' => 'rejected', 'code' => 'backoff_exceeds_policy']);
    expect($this->refresh->refresh(MarketFeed::fx('EUR', 'QAR'), '2026-10-23'))->toMatchArray(['status' => 'blocked', 'code' => 'provider_policy_stop']);
    Http::assertSentCount(1);
});

test('later provider refreshes preserve saved valuation bytes and original observation references', function () {
    FxFixtures::account($this->owner, Currency::QAR);
    $p = M::price($this->owner);
    expect((string) $p['account']->id)->not->toBe((string) $p['account']->account_id);
    PortfolioFixtures::opening($this->owner, $p['account'], [PortfolioFixtures::row($p['asset'], ['quantity' => '1'])], ['as_of' => '2026-10-22']);
    $this->refresh->map($this->owner, 'valuation-provider-reviewed-map', [...$p['input'], 'supersedes_id' => (string) $p['mapping']->id,
        'context' => app(PriceObservationService::class)->context($this->owner, (string) $p['account']->id)]);
    Http::fake(['api.twelvedata.com/*' => Http::sequence()->push(M::priceBody('2026-10-22', '12'))->push(M::priceBody('2026-10-23', '13'))]);
    $this->refresh->refresh($p['feed'], '2026-10-23');
    $price = (string) DB::table('portfolio_prices')->value('id');
    $valuation = app(InvestmentValuation::class);
    $saved = $valuation->save($this->owner, 'provider-valuation-original', Currency::USD, '2026-10-23T12:00:00Z', (string) $p['account']->account_id);
    $bytes = $saved->evidence;
    $evidence = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    expect($evidence['ending']['total']['minor'])->toBe('1200')->and($evidence['ending']['components'][1]['price']['id'])->toBe($price)
        ->and($evidence['ending']['components'][1]['price']['provider_time']['source_date'])->toBe('2026-10-22');
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));
    $this->refresh->refresh($p['feed'], '2026-10-24');
    expect($valuation->save($this->owner, 'provider-valuation-original', Currency::USD, '2026-10-23T12:00:00Z', (string) $p['account']->account_id)->evidence)->toBe($bytes);
    $historical = $valuation->capture($this->owner, Currency::USD, '2026-10-23T12:00:00Z', (string) $p['account']->account_id);
    expect($historical['ending']['total']['minor'])->toBe('1200')->and($historical['ending']['components'][1]['price']['id'])->toBe($price);
    expect($valuation->capture($this->owner, Currency::USD, '2026-10-24T12:00:00Z', (string) $p['account']->account_id)['ending']['total']['minor'])->toBe('1300');
    expect(DB::table('investment_valuation_runs')->value('evidence'))->toBe($bytes);
});

test('shared daily credits do not reset at midnight while retained sends remain inside rolling day', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-23T22:20:00Z'));
    Http::fake(['api.twelvedata.com/*' => fn ($request) => Http::response(M::priceBody('2026-10-22', meta: ['symbol' => $request['symbol'], 'exchange_timezone' => 'UTC']))]);
    for ($i = 0; $i < 800; $i++) {
        if ($i > 0 && $i % 8 === 0) {
            $this->travel(60)->seconds();
        }
        expect($this->refresh->refresh(MarketFeed::price('SYNTH'.$i, 'USD', 'XNAS', 'UTC'), '2026-10-23')['status'])->toBe('accepted');
    }
    $this->travelTo(new DateTimeImmutable('2026-10-24T00:00:00Z'));
    expect($this->refresh->refresh(MarketFeed::price('MIDNIGHT', 'USD', 'XNAS', 'UTC'), '2026-10-24'))->toMatchArray(['status' => 'deferred', 'code' => 'shared_credit_limit']);
    $this->assertDatabaseCount('market_attempts', 800);
    Http::assertSentCount(800);
    $this->travelTo(new DateTimeImmutable('2026-10-24T22:20:00Z'));
    expect($this->refresh->refresh(MarketFeed::price('AFTER24H', 'USD', 'XNAS', 'UTC'), '2026-10-24')['status'])->toBe('accepted');
    $this->assertDatabaseCount('market_attempts', 801);
    Http::assertSentCount(801);
});
