<?php

use App\Domain\Decisions\DecisionRead;
use App\Domain\Decisions\DecisionService;
use App\Domain\Ledger\PostingService;
use App\Domain\Portfolio\InvestmentService;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\DecisionFixtures as F;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->service = app(DecisionService::class);
    $this->read = app(DecisionRead::class);
    $this->account = Fx::account($this->owner, Currency::NGN);
    G::cash($this->owner, $this->account, '1000');
    $this->goal = G::goal($this->owner, $this->account, ['opening' => '0', 'name' => 'Synthetic opportunity cash']);
});

test('F13 public translation reconciles exact proposal tranches and creates no shares reservations or cash postings', function () {
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    $p = F::plan($this->owner);
    $v = $this->read->detail($this->owner, (string) $p->id);
    expect($v['opportunity']['target']['minor'])->toBe('100000')->and(array_column($v['opportunity']['tranches'], 'amount_minor'))->toBe(['70000', '30000'])
        ->and($v['opportunity']['attributes']['evidence']['status']['verified'])->toBeFalse()->and($v['assessment'])->toBeNull()
        ->and($v['opportunity']['concentration']['state'])->toBe('unknown')->and(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('investment_events', 0);
    $this->assertDatabaseCount('decision_reservations', 0);
});
test('unknown watchlist preserves missing target maximum thesis terms and currency comparison', function () {
    $p = F::plan($this->owner, ['status' => 'watchlist', 'target' => '', 'maximum' => '', 'tranches' => '[]', 'rationale' => '', 'review_on' => '']);
    $v = $this->read->row($p);
    expect($v['target'])->toBeNull()->and($v['maximum'])->toBeNull()->and($v['attributes']['rationale'])->toBeNull()->and($v['attributes']['evidence']['price']['value'])->toBeNull()->and($v['concentration']['percent'])->toBeNull();
});
test('invalid allocation money totals evidence and incomplete planned thesis fail without partial records', function (array $change) {
    expect(fn () => F::plan($this->owner, $change))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('decision_opportunities', 0);
})->with(array_map(fn ($c) => [$c], [
    ['target' => '1000.001'], ['target' => '-1'], ['maximum' => '999'], ['target' => '92233720368547758.08'], ['target' => ''], ['rationale' => ''], ['review_on' => '2026-02-30'], ['tranches' => '{}'], ['tranches' => '[null]'],
    ['tranches' => '[{"key":"same","label":"A","amount":"500","condition":"A"},{"key":"same","label":"B","amount":"500","condition":"B"}]'],
    ['tranches' => '[{"key":"x","label":"A","amount":"999.99","condition":"A"}]'], ['offer_price' => '-1'], ['status_url' => 'javascript:alert(1)'], ['status_url' => 'https://user:password@example.com'], ['status_verified_on' => '2026-10-22'], ['currency_threshold' => '100.01'], ['portfolio_total' => '0'], ['currency_exposure' => '-1'], ['portfolio_total' => '100', 'currency_exposure' => '101'], ['reference_on' => '2026-10-22'], ['owner_id' => '999'], ['actor_is_admin' => true],
]));
test('offer fields have independent freshness and an owner source check never infers other claims', function () {
    $p = F::plan($this->owner, ['offer_status' => 'Synthetic open window', 'status_url' => 'https://example.com/official', 'status_verified_on' => '2026-10-21', 'status_review_on' => '2026-10-22', 'status_official' => '1', 'offer_price' => '12.123456789012']);
    $a = $this->read->row($p)['attributes']['evidence'];
    expect($a['status']['verified'])->toBeTrue()->and($a['price']['verified'])->toBeFalse()->and($a['price']['value'])->toBe('12.123456789012')->and($a['eligibility']['value'])->toBeNull();
    $this->travel(2)->days();
    expect($this->read->row($p)['attributes']['evidence']['status']['verified'])->toBeFalse();
});
test('configurable concentration compares exact target with dated same-currency manual reference and expires visibly', function () {
    $p = F::plan($this->owner, ['currency_threshold' => '25', 'portfolio_total' => '10000', 'currency_exposure' => '1500', 'reference_source' => 'Synthetic manual wealth reference', 'reference_on' => '2026-10-21', 'reference_review_on' => '2026-10-22']);
    $v = $this->read->row($p)['concentration'];
    expect($v['state'])->toBe('within_limit')->and($v['percent'])->toBe('25.0000');
    $over = F::plan($this->owner, ['currency_threshold' => '24.999999999999', 'portfolio_total' => '10000', 'currency_exposure' => '1500', 'reference_source' => 'Synthetic reference', 'reference_on' => '2026-10-21', 'reference_review_on' => '2026-10-22']);
    expect($this->read->row($over)['concentration']['state'])->toBe('over_limit');
    $this->travel(2)->days();
    expect($this->read->row($p)['concentration']['state'])->toBe('stale')->and($this->read->row($p)['concentration']['percent'])->toBeNull();
});
test('plan and reservation receipts recover after revisions releases and closure while changed retries fail', function () {
    $input = F::input();
    $p = $this->service->plan($this->owner, 'synthetic-plan-retry', $input);
    $r = F::reserve($this->owner, $p, $this->goal, [], 'synthetic-reserve-retry');
    $next = F::plan($this->owner, ['prior_id' => (string) $p->id, 'rationale' => 'Changed synthetic rationale']);
    expect($this->service->plan($this->owner, 'synthetic-plan-retry', $input)->id)->toBe($p->id)->and(F::reserve($this->owner, $p, $this->goal, [], 'synthetic-reserve-retry')->id)->toBe($r->id);
    expect(fn () => F::reserve($this->owner, $p, $this->goal))->toThrow(ValidationException::class);
    $release = $this->service->release($this->owner, 'synthetic-release-retry', (string) $r->id, ['reason' => 'Synthetic no longer needed']);
    $closed = F::plan($this->owner, ['prior_id' => (string) $next->id, 'status' => 'closed']);
    expect(F::reserve($this->owner, $p, $this->goal, [], 'synthetic-reserve-retry')->id)->toBe($r->id)->and($this->service->release($this->owner, 'synthetic-release-retry', (string) $r->id, ['reason' => 'Synthetic no longer needed'])->id)->toBe($release->id);
    expect(fn () => $this->service->plan($this->owner, 'synthetic-plan-retry', [...$input, 'name' => 'Changed request']))->toThrow(ValidationException::class);
    expect(fn () => F::plan($this->owner, ['prior_id' => (string) $closed->id]))->toThrow(ValidationException::class);
    $v = $this->read->detail($this->owner, (string) $p->id);
    expect($v['is_current'])->toBeFalse()->and($v['current_id'])->toBe((string) $closed->id)->and($v['history'])->toHaveCount(3)->and($v['opportunity']['attributes']['rationale'])->toBe($input['rationale']);
});
test('tranche totals count reservations across all retained versions and prevent hiding protected cash', function () {
    $p = F::plan($this->owner);
    F::reserve($this->owner, $p, $this->goal);
    $next = F::plan($this->owner, ['prior_id' => (string) $p->id]);
    expect(fn () => F::reserve($this->owner, $next, $this->goal, ['amount' => '200.01']))->toThrow(ValidationException::class);
    F::reserve($this->owner, $next, $this->goal, ['amount' => '200']);
    expect($this->read->totals($this->owner, $p->series_id, 'initial')['reserved'])->toBe('70000');
    foreach ([['status' => 'closed'], ['target' => '300', 'tranches' => '[{"key":"reserve","label":"Reserve","amount":"300","condition":"Review"}]']] as $change) {
        expect(fn () => F::plan($this->owner, ['prior_id' => (string) $next->id, ...$change]))->toThrow(ValidationException::class);
    }
});
test('shared account capacity protects other goals and dedicated emergency claims without any posting', function () {
    $p = F::plan($this->owner);
    G::goal($this->owner, $this->account, ['opening' => '800', 'name' => 'Synthetic other goal']);
    $before = DB::table('postings')->where('account_id', $this->account->id)->orderBy('id')->get()->toJson();
    expect(fn () => F::reserve($this->owner, $p, $this->goal))->toThrow(ValidationException::class);
    F::reserve($this->owner, $p, $this->goal, ['amount' => '200']);
    $cash = Fx::account($this->owner, Currency::NGN);
    G::cash($this->owner, $cash);
    $emergency = G::goal($this->owner, $cash, ['mode' => 'dedicated', 'name' => 'Synthetic emergency protection']);
    expect(fn () => F::reserve($this->owner, $p, $emergency, ['amount' => '1']))->toThrow(ValidationException::class);
    expect(DB::table('postings')->where('account_id', $this->account->id)->orderBy('id')->get()->toJson())->toBe($before);
});
test('later withdrawals show account-wide FIFO shortfall without pretending nominal reservations are funded', function () {
    G::goal($this->owner, $this->account, ['opening' => '400', 'name' => 'Synthetic earlier claim']);
    $p = F::plan($this->owner);
    F::reserve($this->owner, $p, $this->goal);
    app(PostingService::class)->record($this->owner, 'synthetic-decision-withdrawal', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '700', 'effective_date' => '2026-10-21', 'description' => 'Synthetic later cash movement']);
    $r = $this->read->detail($this->owner, (string) $p->id)['reservations'][0];
    expect($r['amount']['amount'])->toBe('500.00')->and($r['backing']['amount'])->toBe('0.00')->and($r['backing']['shortfall'])->toBe('500.00');
    expect(fn () => F::reserve($this->owner, $p, $this->goal, ['amount' => '1']))->toThrow(ValidationException::class);
});
test('actual over-plan purchase is attributed as fact exactly once without posting again and reversal retains zero-current-spend history', function () {
    $asset = P::asset($this->owner, ['currency' => 'NGN']);
    $account = P::account($this->owner, Currency::NGN);
    $portfolio = P::link($this->owner, $account);
    I::fund($this->owner, $account);
    $p = F::plan($this->owner, ['asset_id' => (string) $asset->id]);
    $event = I::event($this->owner, $portfolio, $asset, ['amount' => '800', 'quantity' => '100', 'unit_price' => '8', 'fee' => '1', 'tax' => '0']);
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    $link = F::link($this->owner, $p, $event, [], 'synthetic-actual-link-retry');
    expect(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before)->and($this->read->detail($this->owner, (string) $p->id)['opportunity']['tranches'][0]['remaining_minor'])->toBe('-10100');
    expect(fn () => F::link($this->owner, $p, $event))->toThrow(ValidationException::class);
    expect(fn () => F::reserve($this->owner, $p, $this->goal, ['amount' => '1']))->toThrow(ValidationException::class);
    app(InvestmentService::class)->reverse($this->owner, (string) $portfolio->id, (string) $event->id, 'synthetic-decision-reverse', 'Synthetic reversal');
    expect($this->read->totals($this->owner, $p->series_id, 'initial')['spent'])->toBe('0')->and($this->read->detail($this->owner, (string) $p->id)['event_links'][0]['source_changed'])->toBeTrue()->and(F::link($this->owner, $p, $event, [], 'synthetic-actual-link-retry')->id)->toBe($link->id);
});
test('identified asset and native currency stay fixed while unknown watchlist can identify once', function () {
    $a = P::asset($this->owner, ['currency' => 'NGN']);
    $b = P::asset($this->owner, ['currency' => 'NGN']);
    $p = F::plan($this->owner);
    $next = F::plan($this->owner, ['prior_id' => (string) $p->id, 'asset_id' => (string) $a->id]);
    foreach ([['asset_id' => (string) $b->id], ['asset_id' => ''], ['asset_id' => (string) $a->id, 'currency' => 'QAR']] as $c) {
        expect(fn () => F::plan($this->owner, ['prior_id' => (string) $next->id, ...$c]))->toThrow(ValidationException::class);
    }
});

test('actual attribution rejects intentions unpaid subscriptions other exact references and foreign owner events', function () {
    $asset = P::asset($this->owner, ['currency' => 'NGN']);
    $other = P::asset($this->owner, ['currency' => 'NGN']);
    $account = P::account($this->owner, Currency::NGN);
    $portfolio = P::link($this->owner, $account);
    I::fund($this->owner, $account);
    $p = F::plan($this->owner, ['asset_id' => (string) $asset->id]);
    $intent = I::event($this->owner, $portfolio, $asset, ['kind' => 'ipo_intent', 'amount' => '100']);
    $wrong = I::event($this->owner, $portfolio, $other);
    foreach ([$intent, $wrong] as $e) {
        expect(fn () => F::link($this->owner, $p, $e))->toThrow(ValidationException::class);
    }
    $paid = I::event($this->owner, $portfolio, $asset, ['kind' => 'ipo_pay', 'subscription_id' => (string) $intent->id, 'amount' => '100']);
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    F::link($this->owner, $p, $paid);
    expect($this->read->totals($this->owner, $p->series_id, 'initial')['spent'])->toBe('10000')->and(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before);
    $foreign = User::factory()->create();
    $fa = P::asset($foreign, ['currency' => 'NGN']);
    $cash = P::account($foreign, Currency::NGN);
    $fp = P::link($foreign, $cash);
    I::fund($foreign, $cash);
    $event = I::event($foreign, $fp, $fa);
    expect(fn () => F::link($this->owner, $p, $event))->toThrow(NotFoundHttpException::class);
});

test('complete decision export reconstructs released reservations and reversed factual spend with original thesis links', function () {
    $asset = P::asset($this->owner, ['currency' => 'NGN']);
    $account = P::account($this->owner, Currency::NGN);
    $portfolio = P::link($this->owner, $account);
    I::fund($this->owner, $account);
    $p = F::plan($this->owner, ['asset_id' => (string) $asset->id]);
    $r = F::reserve($this->owner, $p, $this->goal);
    $event = I::event($this->owner, $portfolio, $asset);
    F::link($this->owner, $p, $event);
    F::plan($this->owner, ['prior_id' => (string) $p->id, 'asset_id' => (string) $asset->id]);
    $active = $this->read->export($this->owner)['reservation_facts'][0];
    expect($active['released'])->toBeFalse()->and($active['backing']['amount'])->toBe('500.00')->and($active['backing']['shortfall'])->toBe('0.00');
    $this->service->release($this->owner, 'synthetic-export-release', (string) $r->id, ['reason' => 'Synthetic released claim']);
    $reverse = app(InvestmentService::class)->reverse($this->owner, (string) $portfolio->id, (string) $event->id, 'synthetic-export-reverse', 'Synthetic reversed fact');
    $v = $this->read->export($this->owner);
    expect($v['reservations'][0]['opportunity_id'])->toBe((string) $p->id)->and($v['reservation_facts'][0]['amount']['amount'])->toBe('500.00')->and($v['reservation_facts'][0]['account_id'])->toBe((string) $this->account->id)->and($v['reservation_facts'][0]['released'])->toBeTrue()->and($v['reservation_facts'][0]['backing']['amount'])->toBe('0.00')
        ->and($v['actual_event_links'][0]['opportunity_id'])->toBe((string) $p->id)->and($v['actual_event_facts'][0]['gross_attributed_amount'])->toBe('56.08')->and($v['actual_event_facts'][0]['current_attributed_amount'])->toBe('0.00')->and($v['actual_event_facts'][0]['reversed_by'])->toBe((string) $reverse->id);
});
