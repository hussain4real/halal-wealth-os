<?php

use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\PostingService;
use App\Domain\Planning\AvailabilitySettings;
use App\Domain\Planning\BudgetService;
use App\Domain\Planning\CashAvailability;
use App\Domain\Planning\GoalService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AvailabilityFixtures as A;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->account = Fx::account($this->owner);
});
test('anonymous surplus separates liquid cash protection unpaid bills and explicit buffer', function () {
    G::cash($this->owner, $this->account, '1000');
    G::goal($this->owner, $this->account, ['opening' => '400']);
    $bill = B::rule($this->owner, B::category($this->owner), ['amount' => '200', 'first_due_date' => '2026-10-22']);
    A::settings($this->owner, ['requirements' => [['schedule_id' => (string) $bill->id, 'required' => '1']]]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['state'])->toBe('complete')->and($r['actual']['raw']['minor'])->toBe('35000')
        ->and($r['actual']['deployable']['minor'])->toBe('35000')->and($r['actual']['breakdown']['cash']['minor'])->toBe('100000')
        ->and($r['actual']['breakdown']['protected']['minor'])->toBe('40000')->and($r['actual']['breakdown']['unfunded']['minor'])->toBe('20000');
});
test('explicit backed overlap removes one deduction and future salary never becomes actual cash', function () {
    G::cash($this->owner, $this->account, '1000');
    $g = G::goal($this->owner, $this->account, ['opening' => '400']);
    $reservation = DB::table('goal_reservations')->where('goal_id', $g->id)->first();
    $bill = B::rule($this->owner, B::category($this->owner), ['amount' => '400', 'first_due_date' => '2026-10-22']);
    B::rule($this->owner, B::category($this->owner, ['direction' => 'income']), ['salary' => '1', 'amount' => '9000', 'first_due_date' => '2026-10-28']);
    A::settings($this->owner, ['requirements' => [['schedule_id' => (string) $bill->id, 'required' => '1']],
        'overlaps' => [['reservation_id' => (string) $reservation->id, 'schedule_id' => (string) $bill->id, 'due_date' => '2026-10-22', 'amount' => '400']]]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['raw']['minor'])->toBe('55000')->and($r['actual']['breakdown']['cash']['minor'])->toBe('100000')
        ->and($r['actual']['breakdown']['unfunded']['minor'])->toBe('0')->and($r['occurrences'][0]['overlap'])->toBe('40000');
});
test('signed negative cash and raw deficit remain visible instead of funding clamp zero', function () {
    app(PostingService::class)->record($this->owner, 'cash-negative-position', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '20', 'effective_date' => '2026-10-18', 'description' => 'Synthetic overdraft']);
    A::settings($this->owner);
    $r = app(CashAvailability::class)->capture($this->owner)['actual'];
    expect($r['state'])->toBe('complete')->and($r['raw']['minor'])->toBe('-7000')->and($r['deployable']['minor'])->toBe('0')->and($r['shortfall']['minor'])->toBe('7000');
});
test('missing and newly unclassified inputs are incomplete rather than invented zero', function () {
    G::cash($this->owner, $this->account, '1000');
    expect(app(CashAvailability::class)->capture($this->owner)['actual']['deployable'])->toBeNull();
    A::settings($this->owner, ['buffer' => '0']);
    expect(app(CashAvailability::class)->capture($this->owner)['actual']['deployable']['minor'])->toBe('100000');
    B::rule($this->owner, B::category($this->owner));
    $r = app(CashAvailability::class)->capture($this->owner)['actual'];
    expect($r['state'])->toBe('incomplete')->and($r['missing'])->toContain('unclassified_required_commitments')->and($r['deployable'])->toBeNull();
});
test('settings retry returns the committed version before changed-source validation', function () {
    $input = A::input();
    $s = app(AvailabilitySettings::class)->save($this->owner, 'availability-stable-key', $input);
    B::rule($this->owner, B::category($this->owner));
    $same = app(AvailabilitySettings::class)->save($this->owner, 'availability-stable-key', $input);
    expect($same->id)->toBe($s->id);
    $this->assertDatabaseCount('cash_availability_settings', 1);
    expect(fn () => app(AvailabilitySettings::class)->save($this->owner, 'availability-stable-key', [...$input, 'buffer' => '51']))->toThrow(ValidationException::class);
});

test('early actual payment is not deducted twice and reversal reopens the required residual', function () {
    G::cash($this->owner, $this->account, '1000');
    $bill = B::rule($this->owner, B::category($this->owner), ['amount' => '200', 'first_due_date' => '2026-10-22']);
    $expense = app(PostingService::class)->record($this->owner, 'cash-early-paid-bill', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '100', 'effective_date' => '2026-10-18', 'description' => 'Synthetic early payment']);
    app(BudgetService::class)->allocate($this->owner, 'cash-match-early-bill', (string) $bill->id, '2026-10-22', (string) $expense->id, '100');
    A::settings($this->owner, ['requirements' => [['schedule_id' => (string) $bill->id, 'required' => '1']]]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['raw']['minor'])->toBe('75000')->and($r['occurrences'][0]['paid'])->toBe('10000')->and($r['occurrences'][0]['remaining'])->toBe('10000');
    app(PostingService::class)->reverse($this->owner, (string) $expense->id, 'cash-reverse-early-bill', 'Synthetic cancellation');
    $after = app(CashAvailability::class)->capture($this->owner);
    expect($after['actual']['raw']['minor'])->toBe('75000')->and($after['occurrences'][0]['remaining'])->toBe('20000');
});
test('withdrawal underfunding preserves nominal disclosure and uses only FIFO-backed overlap', function () {
    G::cash($this->owner, $this->account, '1000');
    $g = G::goal($this->owner, $this->account, ['opening' => '600']);
    $r = DB::table('goal_reservations')->where('goal_id', $g->id)->first();
    $bill = B::rule($this->owner, B::category($this->owner), ['amount' => '600', 'first_due_date' => '2026-10-22']);
    A::settings($this->owner, ['buffer' => '0', 'requirements' => [['schedule_id' => (string) $bill->id, 'required' => '1']],
        'overlaps' => [['reservation_id' => (string) $r->id, 'schedule_id' => (string) $bill->id, 'due_date' => '2026-10-22', 'amount' => '600']]]);
    app(PostingService::class)->record($this->owner, 'cash-backing-withdraw', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '700', 'effective_date' => '2026-10-18', 'description' => 'Synthetic later withdrawal']);
    $snapshot = app(CashAvailability::class)->capture($this->owner);
    expect($snapshot['actual']['raw']['minor'])->toBe('-30000')->and($snapshot['actual']['deployable']['minor'])->toBe('0')
        ->and($snapshot['actual']['components'][0]['native']['nominal_reserved'])->toBe('60000')
        ->and($snapshot['actual']['components'][0]['native']['reservation_shortfall'])->toBe('30000')
        ->and($snapshot['occurrences'][0]['overlap'])->toBe('30000');
    app(GoalService::class)->release($this->owner, 'cash-release-backing', (string) $r->id, 'Synthetic released protection');
    expect(app(CashAvailability::class)->capture($this->owner)['actual']['raw']['minor'])->toBe('-30000');
});
test('a paid occurrence does not consume another reservation backing before the next linked bill', function () {
    G::cash($this->owner, $this->account, '150');
    $g1 = G::goal($this->owner, $this->account, ['opening' => '50']);
    $g2 = G::goal($this->owner, $this->account, ['opening' => '100']);
    $reservations = DB::table('goal_reservations')->orderBy('id')->get();
    $category = B::category($this->owner);
    $b1 = B::rule($this->owner, $category, ['amount' => '100', 'cadence' => 'once', 'first_due_date' => '2026-10-20']);
    $b2 = B::rule($this->owner, $category, ['amount' => '50', 'cadence' => 'once', 'first_due_date' => '2026-10-21']);
    $expense = app(PostingService::class)->record($this->owner, 'cash-overlap-part-paid', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '50', 'effective_date' => '2026-10-18', 'description' => 'Synthetic half paid']);
    app(BudgetService::class)->allocate($this->owner, 'cash-overlap-half-match', (string) $b1->id, '2026-10-20', (string) $expense->id, '50');
    A::settings($this->owner, ['buffer' => '0', 'requirements' => [['schedule_id' => (string) $b1->id, 'required' => '1'], ['schedule_id' => (string) $b2->id, 'required' => '1']],
        'overlaps' => [['reservation_id' => (string) $reservations[0]->id, 'schedule_id' => (string) $b1->id, 'due_date' => '2026-10-20', 'amount' => '50'],
            ['reservation_id' => (string) $reservations[1]->id, 'schedule_id' => (string) $b1->id, 'due_date' => '2026-10-20', 'amount' => '50'],
            ['reservation_id' => (string) $reservations[1]->id, 'schedule_id' => (string) $b2->id, 'due_date' => '2026-10-21', 'amount' => '50']]]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['breakdown']['unfunded']['minor'])->toBe('0')->and($r['actual']['raw']['minor'])->toBe('0')
        ->and(array_column($r['occurrences'], 'overlap'))->toBe(['5000', '5000']);
});
test('overdue and future-month bills use complete anchored recurrence without monthly provisions', function () {
    G::cash($this->owner, $this->account, '1000');
    $category = B::category($this->owner);
    $monthly = B::rule($this->owner, $category, ['amount' => '100', 'first_due_date' => '2026-08-31']);
    $annual = B::rule($this->owner, $category, ['amount' => '300', 'cadence' => 'annual', 'first_due_date' => '2026-11-01', 'provision_start_date' => '2026-01-01']);
    A::settings($this->owner, ['next_income_date' => '2026-11-01', 'buffer' => '0', 'requirements' => [['schedule_id' => (string) $monthly->id, 'required' => '1'], ['schedule_id' => (string) $annual->id, 'required' => '1']]]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['raw']['minor'])->toBe('40000')->and($r['occurrence_count'])->toBe(4)
        ->and(array_column($r['occurrences'], 'due'))->toBe(['2026-08-31', '2026-09-30', '2026-10-31', '2026-11-01']);
    $this->assertDatabaseCount('planning_occurrences', 0);
});
test('salary flag removal cuts off the previous forecast version instead of reviving old income', function () {
    G::cash($this->owner, $this->account, '1000');
    $category = B::category($this->owner, ['direction' => 'income']);
    $old = B::rule($this->owner, $category, ['salary' => '1', 'amount' => '100', 'first_due_date' => '2026-10-25']);
    app(BudgetService::class)->schedule($this->owner, 'cash-salary-flag-removal', B::input($category, ['salary' => '0', 'amount' => '100', 'first_due_date' => '2026-11-25', 'cutover_date' => '2026-11-01', 'reason' => 'Synthetic no longer salary']), (string) $old->id);
    A::settings($this->owner, ['buffer' => '0']);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['raw']['minor'])->toBe('100000')->and($r['forecast']['missing'])->toContain('salary:no_expected_salary')
        ->and($r['actual']['forecast_inputs']['salary'])->toBe('0');
});
test('dedicated high-water is not a reserve and archived liquid cash remains included', function () {
    G::cash($this->owner, $this->account, '1000');
    G::goal($this->owner, $this->account, ['mode' => 'dedicated', 'opening' => '1000']);
    app(PostingService::class)->record($this->owner, 'cash-dedicated-draw', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '600', 'effective_date' => '2026-10-18', 'description' => 'Synthetic drawdown']);
    $this->account->archived_at = now();
    $this->account->save();
    A::settings($this->owner, ['buffer' => '0']);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['breakdown']['cash']['minor'])->toBe('40000')->and($r['actual']['breakdown']['protected']['minor'])->toBe('40000')
        ->and($r['actual']['raw']['minor'])->toBe('0')->and($r['accounts'][0]['archived'])->toBeTrue();
});
test('required foreign bills need direct current owner reporting FX even without foreign cash', function () {
    G::cash($this->owner, $this->account, '1000');
    $bill = B::rule($this->owner, B::category($this->owner), ['currency' => 'USD', 'amount' => '100', 'first_due_date' => '2026-10-22']);
    A::settings($this->owner, ['buffer' => '0', 'requirements' => [['schedule_id' => (string) $bill->id, 'required' => '1']]]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['state'])->toBe('incomplete')->and($r['actual']['missing'])->toContain('USD:missing_fx')->and($r['actual']['deployable'])->toBeNull();
    app(FxObservationService::class)->record($this->owner, 'cash-required-direct-rate', Fx::quote('USD', 'QAR', '2'));
    expect(app(CashAvailability::class)->capture($this->owner)['actual']['raw']['minor'])->toBe('80000');
});
test('missing past-income and unavailable opening states never masquerade as complete zero', function () {
    A::settings($this->owner, ['buffer' => '0']);
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['missing'])->toContain('next_income_date_needs_confirmation')->and($r['actual']['raw'])->toBeNull();
});
test('foreign schedule reservation and investment identities are rejected before any settings write', function () {
    $other = User::factory()->create();
    $account = Fx::account($other);
    G::cash($other, $account, '100');
    $goal = G::goal($other, $account, ['opening' => '50']);
    $schedule = B::rule($other, B::category($other));
    foreach ([['requirements' => [['schedule_id' => (string) $schedule->id, 'required' => '1']]], ['investment_goals' => [(string) $goal->id]]] as $input) {
        try {
            A::settings($this->owner, $input);
            $this->fail('Foreign identity must fail');
        } catch (NotFoundHttpException) {
        }
    }
    $this->assertDatabaseCount('cash_availability_settings', 0);
});
test('investment target and required bill cannot simultaneously claim the same reservation purpose', function () {
    G::cash($this->owner, $this->account, '1000');
    $goal = G::goal($this->owner, $this->account, ['opening' => '400']);
    $reservation = DB::table('goal_reservations')->first();
    $bill = B::rule($this->owner, B::category($this->owner), ['amount' => '400', 'first_due_date' => '2026-10-22']);
    expect(fn () => A::settings($this->owner, ['requirements' => [['schedule_id' => (string) $bill->id, 'required' => '1']], 'investment_goals' => [(string) $goal->id],
        'overlaps' => [['reservation_id' => (string) $reservation->id, 'schedule_id' => (string) $bill->id, 'due_date' => '2026-10-22', 'amount' => '400']]]))->toThrow(ValidationException::class);
});
test('alpha salary expenses emergency funding and investment reservation conserve actual cash end to end', function () {
    $salary = G::cash($this->owner, $this->account, '1500');
    $incomeCategory = B::category($this->owner, ['direction' => 'income']);
    $income = B::rule($this->owner, $incomeCategory, ['salary' => '1', 'amount' => '1500', 'first_due_date' => '2026-10-18']);
    app(BudgetService::class)->allocate($this->owner, 'cash-alpha-received-salary', (string) $income->id, '2026-10-18', (string) $salary->id, '1500');
    $expense = app(PostingService::class)->record($this->owner, 'cash-alpha-paid-expense', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '500', 'effective_date' => '2026-10-18', 'description' => 'Synthetic alpha living costs']);
    $category = B::category($this->owner);
    $paid = B::rule($this->owner, $category, ['amount' => '500', 'first_due_date' => '2026-10-18']);
    app(BudgetService::class)->allocate($this->owner, 'cash-alpha-match-expense', (string) $paid->id, '2026-10-18', (string) $expense->id, '500');
    $unpaid = B::rule($this->owner, $category, ['amount' => '200', 'first_due_date' => '2026-10-22']);
    G::goal($this->owner, $this->account, ['name' => 'Synthetic emergency protection', 'opening' => '400', 'contribution' => '20']);
    $investment = G::goal($this->owner, $this->account, ['name' => 'Synthetic investment reservation', 'opening' => '100', 'contribution' => '80']);
    A::settings($this->owner, ['investment_target' => '150', 'investment_goals' => [(string) $investment->id],
        'requirements' => [['schedule_id' => (string) $paid->id, 'required' => '1'], ['schedule_id' => (string) $unpaid->id, 'required' => '1']]]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['breakdown']['cash']['minor'])->toBe('100000')->and($r['actual']['breakdown']['protected']['minor'])->toBe('50000')
        ->and($r['actual']['breakdown']['unfunded']['minor'])->toBe('20000')->and($r['actual']['raw']['minor'])->toBe('25000')
        ->and($r['forecast']['details']['additional_investment']['minor'])->toBe('5000')->and($r['actual']['forecast_inputs']['contribution'])->toBe('2000');
    $this->assertDatabaseCount('journal_entries', 2);
    $this->assertDatabaseCount('goal_reservations', 2);
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    app(CashAvailability::class)->scenario($this->owner, $r['fingerprint'], ['salary' => '0', 'contribution' => '0', 'investment_target' => '0', 'commitments' => '0']);
    expect(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before);
});

test('next-month salary already received is actual cash plus only the unpaid forecast residual', function () {
    $salary = B::rule($this->owner, B::category($this->owner, ['direction' => 'income']), ['salary' => '1', 'amount' => '100', 'first_due_date' => '2026-11-25']);
    $received = G::cash($this->owner, $this->account, '40');
    app(BudgetService::class)->allocate($this->owner, 'cash-next-month-early-salary', (string) $salary->id, '2026-11-25', (string) $received->id, '40');
    A::settings($this->owner, ['buffer' => '0']);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['raw']['minor'])->toBe('4000')->and($r['actual']['forecast_inputs']['salary'])->toBe('6000')
        ->and($r['forecast']['cash']['minor'])->toBe('10000');
    app(PostingService::class)->reverse($this->owner, (string) $received->id, 'cash-early-salary-reversal', 'Synthetic cancelled early salary');
    $after = app(CashAvailability::class)->capture($this->owner);
    expect($after['actual']['raw']['minor'])->toBe('0')->and($after['actual']['forecast_inputs']['salary'])->toBe('10000')
        ->and($after['forecast']['cash']['minor'])->toBe('10000');
});
