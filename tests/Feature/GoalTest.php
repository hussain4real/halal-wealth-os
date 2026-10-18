<?php

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\PostingService;
use App\Domain\Planning\BudgetService;
use App\Domain\Planning\BudgetView;
use App\Domain\Planning\EssentialRunRate;
use App\Domain\Planning\GoalService;
use App\Domain\Planning\GoalView;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->account = Fx::account($this->owner);
    G::cash($this->owner, $this->account);
});
function goalSummary(User $owner, string $id): array
{
    return app(GoalView::class)->show($owner, $id, '2026-10')['goal'];
}
test('opening reservation uses existing cash once with twelve deposits and no extra ledger', function () {
    $before = DB::table('journal_entries')->get()->toJson();
    $goal = G::goal($this->owner, $this->account);
    $r = goalSummary($this->owner, (string) $goal->id);
    expect($r['backed']['minor'])->toBe('480000')->and($r['nominal']['minor'])->toBe('480000')->and($r['projection']['deposits'])->toBe('12')->and($r['essential'])->toBeNull()
        ->and(DB::table('journal_entries')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('goal_reservations', 1);
});
test('posted transfer increases dedicated funding once and annotations do not post', function () {
    $goal = G::goal($this->owner, $this->account, ['mode' => 'dedicated']);
    $source = Fx::account($this->owner);
    G::cash($this->owner, $source, '1600');
    $j = app(PostingService::class)->record($this->owner, 'goal-transfer-real', ['kind' => 'transfer', 'account_id' => (string) $source->id, 'destination_account_id' => (string) $this->account->id, 'amount' => '1600', 'effective_date' => '2026-10-18', 'description' => 'Synthetic goal contribution']);
    $p = DB::table('postings')->where('journal_id', $j->id)->where('account_id', $this->account->id)->first();
    $before = DB::table('postings')->get()->toJson();
    $link = app(GoalService::class)->link($this->owner, 'goal-link-transfer', (string) $goal->id, (string) $p->id, 'Synthetic posted deposit');
    $again = app(GoalService::class)->link($this->owner, 'goal-link-transfer', (string) $goal->id, (string) $p->id, 'Synthetic posted deposit');
    expect($link->id)->toBe($again->id)->and(goalSummary($this->owner, (string) $goal->id)['backed']['minor'])->toBe('640000')->and(DB::table('postings')->get()->toJson())->toBe($before);
    expect(fn () => app(GoalService::class)->link($this->owner, 'goal-link-new-key', (string) $goal->id, (string) $p->id, 'Duplicate link'))->toThrow(ValidationException::class);
    app(PostingService::class)->reverse($this->owner, (string) $j->id, 'goal-reverse-transfer', 'Synthetic cancelled transfer');
    $r = app(GoalView::class)->show($this->owner, (string) $goal->id, '2026-10');
    expect($r['goal']['backed']['minor'])->toBe('480000')->and(collect($r['movements'])->where('posting_id', (string) $p->id)->first()['status'])->toBe('reversed');
});
test('shared FIFO reservations show later withdrawal shortfall and cash restoration', function () {
    $a = G::goal($this->owner, $this->account, ['opening' => '3000']);
    $b = G::goal($this->owner, $this->account, ['opening' => '1800']);
    $expense = app(PostingService::class)->record($this->owner, 'goal-withdrawal', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '2000', 'effective_date' => '2026-10-18', 'description' => 'Synthetic later withdrawal']);
    $ra = goalSummary($this->owner, (string) $a->id);
    $rb = goalSummary($this->owner, (string) $b->id);
    expect($ra['backed']['minor'])->toBe('280000')->and($ra['shortfall']['minor'])->toBe('20000')->and($rb['backed']['minor'])->toBe('0')->and($rb['nominal']['minor'])->toBe('180000');
    app(PostingService::class)->reverse($this->owner, (string) $expense->id, 'goal-restore-cash', 'Synthetic restoration');
    expect(goalSummary($this->owner, (string) $b->id)['backed']['minor'])->toBe('180000');
});
test('dedicated backing is exclusive against both modes and reserved goals cannot capture dedicated cash', function (string $first, string $second) {
    G::goal($this->owner, $this->account, ['mode' => $first]);
    expect(fn () => G::goal($this->owner, $this->account, ['mode' => $second, 'opening' => $second === 'reserved' ? '0' : '4800']))->toThrow(ValidationException::class);
})->with([['dedicated', 'dedicated'], ['dedicated', 'reserved'], ['reserved', 'dedicated']]);
test('release is durable idempotent capacity recovery without moving cash and closed goal stops claiming', function () {
    $g = G::goal($this->owner, $this->account);
    $id = (string) $g->id;
    $r = DB::table('goal_reservations')->first();
    expect(fn () => app(GoalService::class)->plan($this->owner, 'close-with-reservation', G::input($this->account, ['closed' => '1', 'reason' => 'Synthetic close']), $id))->toThrow(ValidationException::class);
    $release = app(GoalService::class)->release($this->owner, 'goal-release-one', (string) $r->id, 'Synthetic released claim');
    expect(app(GoalService::class)->release($this->owner, 'goal-release-one', (string) $r->id, 'Synthetic released claim')->id)->toBe($release->id);
    $closed = app(GoalService::class)->plan($this->owner, 'close-released-goal', G::input($this->account, ['closed' => '1', 'reason' => 'Synthetic close']), $id);
    $new = G::goal($this->owner, $this->account, ['mode' => 'dedicated']);
    expect(goalSummary($this->owner, $id)['backed']['minor'])->toBe('0')->and(goalSummary($this->owner, (string) $new->id)['backed']['minor'])->toBe('480000');
    $this->assertDatabaseCount('journal_entries', 1);
});
test('plan retries recover original result after revision and payload conflicts never duplicate history', function () {
    $svc = app(GoalService::class);
    $input = G::input($this->account);
    $g = $svc->plan($this->owner, 'goal-plan-recovery', $input);
    $svc->plan($this->owner, 'goal-plan-revision', [...$input, 'contribution' => '0', 'reason' => 'Synthetic paused contributions'], (string) $g->id);
    expect($svc->plan($this->owner, 'goal-plan-recovery', $input)->id)->toBe($g->id);
    expect(fn () => $svc->plan($this->owner, 'goal-plan-recovery', [...$input, 'target' => '1']))->toThrow(ValidationException::class);
    expect(goalSummary($this->owner, (string) $g->id)['projection']['status'])->toBe('unreachable');
    $this->assertDatabaseCount('goal_plans', 2);
});
test('reservation retry recovers after cash spent and full release while new claim fails', function () {
    $g = G::goal($this->owner, $this->account, ['opening' => '0']);
    $svc = app(GoalService::class);
    $r = $svc->reserve($this->owner, 'goal-reserve-retry', (string) $g->id, '4800', 'Synthetic claim');
    app(PostingService::class)->record($this->owner, 'goal-spend-all', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '4800', 'effective_date' => '2026-10-18', 'description' => 'Synthetic spent cash']);
    expect($svc->reserve($this->owner, 'goal-reserve-retry', (string) $g->id, '4800', 'Synthetic claim')->id)->toBe($r->id);
    expect(fn () => $svc->reserve($this->owner, 'goal-reserve-extra', (string) $g->id, '0.01', 'Synthetic extra'))->toThrow(ValidationException::class);
});
test('pending openings cannot fund goals and unsupported backing accounts are rejected', function () {
    $a = app(CreateAccount::class)->create($this->owner, 'Synthetic pending', AccountType::Bank, Currency::QAR, Money::fromDecimal(Currency::QAR, '4800'));
    expect(fn () => G::goal($this->owner, $a))->toThrow(ValidationException::class);
    app(PostingService::class)->postOpening($this->owner, (string) $a->id, 'goal-post-opening');
    expect(G::goal($this->owner, $a)->id)->toBeInt();
    $liability = app(CreateAccount::class)->create($this->owner, 'Synthetic debt', AccountType::Liability, Currency::QAR, null);
    expect(fn () => G::goal($this->owner, $liability, ['opening' => '0']))->toThrow(ValidationException::class);
});
test('negative policy amounts and phantom opening rejected but zero contribution accepted', function (string $field, string $amount) {
    expect(fn () => G::goal($this->owner, $this->account, [$field => $amount]))->toThrow(ValidationException::class);
})->with([['contribution', '-1'], ['opening', '5000'], ['target', '0'], ['contribution', '0.001']]);
test('essential denominator matches native monthend policy without activity payload', function () {
    $normal = B::category($this->owner, ['normal_budget' => '1', 'emergency_essential' => '0']);
    B::rule($this->owner, $normal);
    $essential = B::category($this->owner, ['normal_budget' => '0', 'emergency_essential' => '1']);
    B::rule($this->owner, $essential, ['cadence' => 'annual', 'amount' => '120.01', 'first_due_date' => '2026-01-31', 'provision_start_date' => '2026-01-01']);
    B::rule($this->owner, $essential, ['cadence' => 'once', 'amount' => '999', 'first_due_date' => '2026-10-18']);
    $actual = app(EssentialRunRate::class)->month($this->owner, '2026-10');
    $budget = app(BudgetView::class)->month($this->owner, '2026-10');
    expect($actual['QAR'])->toBe('1001')->and($budget['totals'][0]['essential_monthly']['minor'])->toBe('1001');
    $g = G::goal($this->owner, $this->account);
    $r = goalSummary($this->owner, (string) $g->id);
    expect($r['projection']['coverage_months'])->toBe('479.52')->and($r['target']['minor'])->toBe('2400000');
});
test('cross currency essential totals never imply parity and standing orders never enable reminders', function () {
    $c = B::category($this->owner, ['emergency_essential' => '1']);
    B::rule($this->owner, $c, ['currency' => 'USD']);
    $g = G::goal($this->owner, $this->account);
    $r = goalSummary($this->owner, (string) $g->id);
    expect($r['essential'])->toBeNull()->and($r['projection']['coverage_months'])->toBeNull()->and($r['reminders'])->toBeFalse()->and($r['standing_order'])->toBeTrue();
});

test('dedicated recorded highwater reconciles correction of a precreation receipt without replacement double count', function () {
    $receipt = G::cash($this->owner, $this->account, '1600');
    $g = G::goal($this->owner, $this->account, ['mode' => 'dedicated', 'opening' => '6400']);
    app(PostingService::class)->correct($this->owner, (string) $receipt->id, 'goal-precreation-correct', ['kind' => 'income', 'account_id' => (string) $this->account->id, 'amount' => '800', 'effective_date' => '2026-10-18', 'description' => 'Synthetic corrected original cash']);
    $r = goalSummary($this->owner, (string) $g->id);
    expect($r['backed']['minor'])->toBe('560000')->and($r['nominal']['minor'])->toBe('640000')->and($r['shortfall']['minor'])->toBe('80000');
});
test('archived backing stays readable and no new reservation can be granted', function () {
    $g = G::goal($this->owner, $this->account, ['opening' => '100']);
    $this->account->archived_at = now();
    $this->account->save();
    $r = goalSummary($this->owner, (string) $g->id);
    expect($r['account_archived'])->toBeTrue()->and($r['backed']['minor'])->toBe('10000');
    expect(fn () => app(GoalService::class)->reserve($this->owner, 'archived-new-claim', (string) $g->id, '1', 'Synthetic forbidden claim'))->toThrow(ValidationException::class);
});
test('cash overflow remains unavailable and does not erase nominal reservations', function () {
    $g = G::goal($this->owner, $this->account);
    G::cash($this->owner, $this->account, '92233720368547758.07');
    $r = goalSummary($this->owner, (string) $g->id);
    expect($r['backed'])->toBeNull()->and($r['nominal']['minor'])->toBe('480000')->and($r['projection']['status'])->toBe('backing_unavailable');
});
test('JPY and KWD native money remain exact through goal claims', function (string $currency, string $cash, string $minor) {
    $a = Fx::account($this->owner, Currency::from($currency));
    G::cash($this->owner, $a, $cash);
    $g = G::goal($this->owner, $a, ['opening' => $cash, 'target' => $cash, 'contribution' => '0']);
    $r = goalSummary($this->owner, (string) $g->id);
    expect($r['backed']['minor'])->toBe($minor)->and($r['projection']['status'])->toBe('reached');
})->with([['JPY', '9223372036854775807', '9223372036854775807'], ['KWD', '1234.567', '1234567']]);
test('essential version cutover and enddate parity stays equal to budget monthend policy', function () {
    $c = B::category($this->owner, ['emergency_essential' => '1']);
    $r = B::rule($this->owner, $c, ['first_due_date' => '2026-10-01', 'amount' => '100']);
    app(BudgetService::class)->schedule($this->owner, 'essential-cutover-goal', B::input($c, ['first_due_date' => '2026-10-31', 'provision_start_date' => '2026-10-31', 'cutover_date' => '2026-10-31', 'amount' => '50', 'reason' => 'Synthetic endmonth revision']), (string) $r->id);
    B::rule($this->owner, $c, ['first_due_date' => '2026-10-01', 'end_date' => '2026-10-30', 'amount' => '999']);
    $essential = app(EssentialRunRate::class)->month($this->owner, '2026-10');
    $budget = app(BudgetView::class)->month($this->owner, '2026-10');
    expect($essential['QAR'])->toBe('5000')->and($budget['totals'][0]['essential_monthly']['minor'])->toBe('5000');
});
