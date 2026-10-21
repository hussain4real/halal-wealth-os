<?php

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\ReconciliationService;
use App\Domain\Planning\CashAvailability;
use App\Domain\Reporting\DashboardSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\DashboardFixtures as D;
use Tests\Support\FxFixtures as F;
use Tests\Support\GoalFixtures as G;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\Support\ValuationFixtures as V;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->service = app(DashboardSnapshot::class);
    $this->account = F::account($this->owner);
    G::cash($this->owner, $this->account, '1000');
});

test('M5P1 current headlines use one selected signed source set without financial writes or providers', function () {
    Http::preventStrayRequests();
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    $v = $this->service->current($this->owner, Currency::QAR);
    expect($v['facts']['widgets']['net_worth']['total']['amount'])->toBe('1000.00')->and($v['facts']['widgets']['liquid_cash']['total']['amount'])->toBe('1000.00')
        ->and($v['facts']['widgets']['liabilities']['total']['amount'])->toBe('0.00')->and($v['facts']['widgets']['funded_goals']['total']['amount'])->toBe('0.00')
        ->and($v['facts']['sources']['net_worth'])->toHaveCount(1)->and(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('dashboard_snapshots', 0);
    Http::assertNothingSent();
});
test('liability is signed once while protected goal cash stays a subset', function () {
    $liability = app(CreateAccount::class)->create($this->owner, 'Synthetic liability', AccountType::Liability, Currency::QAR, null);
    app(PostingService::class)->record($this->owner, 'synthetic-liability-expense', ['kind' => 'expense', 'account_id' => (string) $liability->id, 'amount' => '200', 'effective_date' => '2026-10-21', 'description' => 'Synthetic debt fact']);
    G::goal($this->owner, $this->account, ['opening' => '300']);
    $v = $this->service->current($this->owner, Currency::QAR)['facts'];
    expect($v['widgets']['net_worth']['total']['amount'])->toBe('800.00')->and($v['widgets']['liabilities']['total']['amount'])->toBe('-200.00')
        ->and($v['widgets']['funded_goals']['total']['amount'])->toBe('300.00')->and($v['widgets']['liquid_cash']['total']['amount'])->toBe('1000.00');
});
test('missing reporting rate affects base widgets but retains exact native amount and source', function () {
    $foreign = F::account($this->owner, Currency::USD);
    G::cash($this->owner, $foreign, '7.25');
    $v = $this->service->current($this->owner, Currency::QAR)['facts'];
    expect($v['widgets']['net_worth']['state'])->toBe('partial')->and($v['widgets']['net_worth']['total'])->toBeNull()
        ->and($v['widgets']['net_worth']['covered_subtotal']['amount'])->toBe('1000.00')->and($v['widgets']['net_worth']['missing'])->toBe(1)
        ->and($v['sources']['net_worth'][1]['native']['amount'])->toBe('7.25')->and($v['sources']['net_worth'][1]['converted'])->toBeNull();
});
test('missing holding quote does not turn the investment widget into a fabricated total', function () {
    $a = P::account($this->owner);
    $p = P::link($this->owner, $a);
    $asset = P::asset($this->owner);
    P::opening($this->owner, $p, []);
    P::mode($this->owner, $p);
    I::fund($this->owner, $a);
    I::event($this->owner, $p, $asset);
    $v = $this->service->current($this->owner, Currency::QAR)['facts'];
    expect($v['widgets']['investments']['total'])->toBeNull()->and($v['widgets']['net_worth']['state'])->toBe('partial');
    V::price($this->owner, $p, $asset, ['unit_price' => '7']);
    $v = $this->service->current($this->owner, Currency::QAR)['facts'];
    expect($v['widgets']['investments']['total']['amount'])->toBe('1999.92')->and($v['widgets']['net_worth']['total']['amount'])->toBe('2999.92');
});
test('paid IPO receivable is counted once before allotment and aggregate excludes detailed pieces', function () {
    $a = P::account($this->owner);
    $p = P::link($this->owner, $a);
    $asset = P::asset($this->owner, ['kind' => 'equity']);
    P::opening($this->owner, $p, []);
    P::mode($this->owner, $p);
    I::fund($this->owner, $a, '500');
    $subscription = I::event($this->owner, $p, $asset, ['kind' => 'ipo_intent', 'amount' => '100', 'fee' => '0']);
    I::event($this->owner, $p, $asset, ['kind' => 'ipo_pay', 'amount' => '100', 'fee' => '0', 'subscription_id' => (string) $subscription->id]);
    $v = $this->service->current($this->owner, Currency::QAR)['facts'];
    expect($v['widgets']['investments']['total']['amount'])->toBe('500.00')->and(array_column($v['sources']['investments'], 'kind'))->toBe(['cash', 'receivable']);
    P::mode($this->owner, $p, ['mode' => 'aggregate', 'aggregate_value' => '550', 'aggregate_as_of' => '2026-10-21']);
    $v = $this->service->current($this->owner, Currency::QAR)['facts'];
    expect($v['widgets']['investments']['total']['amount'])->toBe('550.00')->and(array_column($v['sources']['investments'], 'kind'))->toBe(['aggregate']);
});
test('complete cash and goal source collection remains complete beyond disclosure page twenty', function () {
    for ($i = 0; $i < 22; $i++) {
        $a = F::account($this->owner);
        G::cash($this->owner, $a, '10');
        G::goal($this->owner, $a, ['opening' => '2']);
    }
    $v = $this->service->current($this->owner, Currency::QAR)['facts'];
    expect($v['sources']['net_worth'])->toHaveCount(23)->and($v['cash']['accounts'])->toHaveCount(23)->and($v['goals'])->toHaveCount(22)
        ->and($v['widgets']['funded_goals']['total']['amount'])->toBe('44.00')->and($v['widgets']['net_worth']['total']['amount'])->toBe('1220.00');
    expect(app(CashAvailability::class)->capture($this->owner)['accounts'])->toHaveCount(20);
});
test('saved snapshot preserves complete original facts labels source dates settings actions and hashes', function () {
    $s = $this->service->save($this->owner, 'synthetic-snapshot-retained', ['base_currency' => 'QAR', 'settings_id' => '']);
    $before = $this->service->open($this->owner, (string) $s->id);
    DB::table('accounts')->where('id', $this->account->id)->update(['name' => 'Synthetic corrected account label']);
    G::cash($this->owner, $this->account, '100');
    $this->travel(2)->days();
    $after = $this->service->open($this->owner, (string) $s->id);
    expect($after)->toBe($before)->and($after['facts']['widgets']['net_worth']['total']['amount'])->toBe('1000.00')
        ->and($this->service->current($this->owner, Currency::QAR)['facts']['widgets']['net_worth']['total']['amount'])->toBe('1100.00');
});
test('snapshot exact retry returns original before changed settings date and ledger validation', function () {
    $input = ['base_currency' => 'QAR', 'settings_id' => ''];
    $s = $this->service->save($this->owner, 'synthetic-retry-snapshot', $input);
    $this->service->configure($this->owner, 'synthetic-settings-later', D::settings());
    $this->travel(2)->days();
    expect($this->service->save($this->owner, 'synthetic-retry-snapshot', $input)->id)->toBe($s->id);
    expect(fn () => $this->service->save($this->owner, 'synthetic-retry-snapshot', [...$input, 'base_currency' => 'USD']))->toThrow(ValidationException::class);
    expect(fn () => $this->service->save($this->owner, 'synthetic-new-snapshot', $input))->toThrow(ValidationException::class);
});
test('repeated current evaluation creates no action rows and no duplicate keys', function () {
    $a = $this->service->current($this->owner, Currency::QAR);
    $b = $this->service->current($this->owner, Currency::QAR);
    expect(array_column($a['actions'], 'key'))->toBe(array_column($b['actions'], 'key'))->and($a['actions'])->toHaveCount(1);
    $this->assertDatabaseCount('dashboard_dismissals', 0);
});
test('current dismissal retains exact retry and alternate-key receipt while later evidence resurfaces', function () {
    $a = $this->service->current($this->owner, Currency::QAR)['actions'][0];
    $input = ['snapshot_id' => '', 'base_currency' => 'QAR', 'action_key' => $a['key'], 'reason' => 'Synthetic reviewed episode'];
    $r = $this->service->dismiss($this->owner, 'synthetic-dismiss-one', $input);
    $other = $this->service->dismiss($this->owner, 'synthetic-dismiss-two', $input);
    expect($r->dismissal_id)->toBe($other->dismissal_id)->and($this->service->current($this->owner, Currency::QAR)['actions'][0]['dismissed'])->toBeTrue();
    $this->assertDatabaseCount('dashboard_dismissals', 1);
    $this->assertDatabaseCount('dashboard_dismissal_requests', 2);
    app(ReconciliationService::class)->record($this->owner, 'synthetic-comparison', (string) $this->account->id, '900', '2026-10-21T12:00:00Z');
    expect($this->service->dismiss($this->owner, 'synthetic-dismiss-two', $input)->id)->toBe($other->id)->and($this->service->current($this->owner, Currency::QAR)['actions'][0]['dismissed'])->toBeFalse();
});
test('settings history has one owned successor and old exact requests recover', function () {
    $a = $this->service->configure($this->owner, 'synthetic-rules-one', D::settings());
    $b = $this->service->configure($this->owner, 'synthetic-rules-two', D::settings(['settings_id' => (string) $a->id, 'evidence' => '0']));
    expect($b->version)->toBe(2)->and($this->service->configure($this->owner, 'synthetic-rules-one', D::settings())->id)->toBe($a->id);
    expect(fn () => $this->service->configure($this->owner, 'synthetic-rules-stale', D::settings()))->toThrow(ValidationException::class);
});
test('invalid explicit rule configuration is rejected', function (array $change) {
    expect(fn () => $this->service->configure($this->owner, (string) Str::uuid(), D::settings($change)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('dashboard_settings', 0);
})->with(array_map(fn ($x) => [$x], [['grace_days' => '-1'], ['grace_days' => '366'], ['grace_days' => '0.5'], ['evidence' => 'true'], ['targets' => '{}'], ['targets' => '[null]'], ['targets' => '[{"currency":"QAR","target":100,"tolerance":"1"}]'], ['targets' => '[{"currency":"QAR","target":"99","tolerance":"1"}]'], ['targets' => '[{"currency":"QAR","target":"100","tolerance":"101"}]']]));
