<?php

use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\FxService;
use App\Domain\Ledger\PostingService;
use App\Domain\Planning\BudgetCalendar;
use App\Domain\Planning\BudgetService;
use App\Domain\Planning\BudgetView;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\FxFixtures as Fx;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
});

test('anonymous F06 translation keeps normal and essential flags independent and never posts forecasts', function (string $normal, string $essential) {
    $owner = User::factory()->create();
    $category = B::category($owner, ['normal_budget' => $normal, 'emergency_essential' => $essential]);
    B::rule($owner, $category);
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['totals'][0]['planned_expense']['minor'])->toBe($normal === '1' ? '14725' : '0')
        ->and($view['totals'][0]['essential_monthly']['minor'])->toBe($essential === '1' ? '14725' : '0')
        ->and($view['occurrences'][0]['status'])->toBe('unpaid');
    $this->assertDatabaseCount('journal_entries', 0);
    $this->assertDatabaseCount('postings', 0);
    $this->assertDatabaseCount('planning_occurrences', 0); // GET is a pure projection.
})->with([['1', '0'], ['1', '1'], ['0', '1'], ['0', '0']]);

test('salary is forecast only and matching annotates cash that already posted once', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $category = B::category($owner, ['name' => 'Synthetic earnings', 'direction' => 'income']);
    $rule = B::rule($owner, $category, ['salary' => '1', 'amount' => '7421.35']);
    expect(app(AccountBalanceReader::class)->forAccount($account)->money->minor)->toBe('0');
    $journal = app(PostingService::class)->record($owner, 'salary-posted-01', ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '7421.35', 'effective_date' => '2026-10-18', 'description' => 'Synthetic salary receipt']);
    $before = DB::table('journal_entries')->get()->toJson();
    $result = app(BudgetService::class)->allocate($owner, 'salary-match-001', (string) $rule->id, '2026-10-18', (string) $journal->id, '7421.35');
    $replay = app(BudgetService::class)->allocate($owner, 'salary-match-001', (string) $rule->id, '2026-10-18', (string) $journal->id, '7421.35');
    expect($replay->id)->toBe($result->id)->and(DB::table('journal_entries')->get()->toJson())->toBe($before)
        ->and(app(AccountBalanceReader::class)->forAccount($account->fresh())->money->minor)->toBe('742135');
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['occurrences'][0]['status'])->toBe('matched')->and($view['totals'][0]['actual_income']['minor'])->toBe('742135');
    $this->assertDatabaseCount('journal_entries', 1);
});

test('term annual and standing-order forecasts use configured cadence with a separate conservative provision', function () {
    $owner = User::factory()->create();
    $category = B::category($owner);
    B::rule($owner, $category, ['name' => 'Synthetic school term', 'amount' => '431.17', 'cadence' => 'term', 'interval_months' => '4', 'first_due_date' => '2026-01-31', 'provision_start_date' => '2026-01-01']);
    B::rule($owner, $category, ['name' => 'Synthetic annual renewal', 'amount' => '120.01', 'cadence' => 'annual', 'first_due_date' => '2026-01-31', 'provision_start_date' => '2026-01-01', 'standing_order' => '1']);
    $feb = app(BudgetView::class)->month($owner, '2026-02');
    $may = app(BudgetView::class)->month($owner, '2026-05');
    expect($feb['occurrences'])->toBeEmpty()->and(array_column(array_column($feb['provisions'], 'amount'), 'minor'))->toBe(['10780', '1001'])
        ->and($may['occurrences'])->toHaveCount(1)->and($may['occurrences'][0]['expected']['minor'])->toBe('43117')
        ->and($may['totals'][0]['planned_expense']['minor'])->toBe('43117');
    $this->assertDatabaseCount('journal_entries', 0);
});

test('one occurrence accepts multiple actuals and one actual splits across occurrences without duplicate expenses', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $category = B::category($owner);
    $rule = B::rule($owner, $category);
    $service = app(BudgetService::class);
    $posting = app(PostingService::class);
    $input = ['kind' => 'expense', 'account_id' => (string) $account->id, 'effective_date' => '2026-10-18', 'description' => 'Synthetic partial payment'];
    $a = $posting->record($owner, 'partial-first-01', [...$input, 'amount' => '60.00']);
    $b = $posting->record($owner, 'partial-second-01', [...$input, 'amount' => '87.25']);
    $service->allocate($owner, 'match-first-001', (string) $rule->id, '2026-10-18', (string) $a->id, '60.00');
    expect(app(BudgetView::class)->month($owner, '2026-10')['occurrences'][0]['status'])->toBe('partial');
    $service->allocate($owner, 'match-second-001', (string) $rule->id, '2026-10-18', (string) $b->id, '87.25');
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['occurrences'][0]['status'])->toBe('matched')->and($view['occurrences'][0]['allocations'])->toHaveCount(2)
        ->and($view['totals'][0]['actual_expense']['minor'])->toBe('14725');
    expect(fn () => $service->allocate($owner, 'duplicate-pair-01', (string) $rule->id, '2026-10-18', (string) $a->id, '1.00'))->toThrow(ValidationException::class);
    $c = $posting->record($owner, 'split-third-0001', [...$input, 'amount' => '147.25']);
    $r1 = B::rule($owner, $category, ['amount' => '60.00']);
    $r2 = B::rule($owner, $category, ['amount' => '87.25']);
    $service->allocate($owner, 'split-first-0001', (string) $r1->id, '2026-10-18', (string) $c->id, '60.00');
    $service->allocate($owner, 'split-second-001', (string) $r2->id, '2026-10-18', (string) $c->id, '87.25');
    $this->assertDatabaseCount('journal_entries', 3);
    expect(DB::table('planning_allocations')->count())->toBe(4);
});

test('reversals reopen matched plans and correction replacements stay explicitly unclassified until matched', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $category = B::category($owner);
    $rule = B::rule($owner, $category);
    $input = ['kind' => 'expense', 'account_id' => (string) $account->id, 'amount' => '147.25', 'effective_date' => '2026-10-15', 'description' => 'Synthetic original'];
    $journal = app(PostingService::class)->record($owner, 'history-budget-01', $input);
    $match = app(BudgetService::class)->allocate($owner, 'history-match-01', (string) $rule->id, '2026-10-18', (string) $journal->id, '147.25');
    $saved = app(FinancialSnapshot::class)->save($owner, 'before-budget-fix', Currency::QAR, '2026-10-18T12:00:00Z');
    $savedBytes = $saved->evidence;
    $replacement = app(PostingService::class)->correct($owner, (string) $journal->id, 'correct-budget-01', [...$input, 'effective_date' => '2026-10-16']);
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['occurrences'][0]['status'])->toBe('unpaid')->and($view['occurrences'][0]['needs_review'])->toBeTrue()
        ->and($view['totals'][0]['actual_expense']['minor'])->toBe('0')->and($view['totals'][0]['unclassified_expense']['minor'])->toBe('14725');
    app(BudgetService::class)->allocate($owner, 'replacement-match', (string) $rule->id, '2026-10-18', (string) $replacement->id, '147.25');
    app(BudgetService::class)->release($owner, 'release-old-match', (string) $match->id, 'Acknowledge corrected receipt');
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['occurrences'][0]['status'])->toBe('matched')->and($view['occurrences'][0]['needs_review'])->toBeFalse()
        ->and($view['totals'][0]['actual_expense']['minor'])->toBe('14725')->and(DB::table('valuation_reports')->where('id', $saved->id)->value('evidence'))->toBe($savedBytes);
});

test('future due revisions retain occurrence versions and posted history at the same or earlier future due date', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $category = B::category($owner);
    $rule = B::rule($owner, $category, ['first_due_date' => '2026-10-20']);
    $journal = app(PostingService::class)->record($owner, 'prepaid-future-01', ['kind' => 'expense', 'account_id' => (string) $account->id, 'amount' => '147.25', 'effective_date' => '2026-10-18', 'description' => 'Synthetic prepayment']);
    $match = app(BudgetService::class)->allocate($owner, 'prepaid-match-01', (string) $rule->id, '2026-10-20', (string) $journal->id, '147.25');
    $revision = B::input($category, ['amount' => '150.00', 'first_due_date' => '2026-10-19', 'cutover_date' => '2026-10-19', 'reason' => 'Synthetic due date change']);
    expect(fn () => app(BudgetService::class)->schedule($owner, 'blocked-revision', $revision, (string) $rule->id))->toThrow(ValidationException::class);
    app(BudgetService::class)->release($owner, 'release-prepaid-01', (string) $match->id, 'Review revised plan');
    $bytes = DB::table('journal_entries')->get()->toJson();
    $new = app(BudgetService::class)->schedule($owner, 'allowed-revision', $revision, (string) $rule->id);
    app(BudgetService::class)->allocate($owner, 'new-future-match', (string) $new->id, '2026-10-19', (string) $journal->id, '147.25');
    expect(DB::table('journal_entries')->get()->toJson())->toBe($bytes);
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect(array_column($view['occurrences'], 'status'))->toBe(['partial', 'superseded']);
    $this->assertDatabaseCount('planning_occurrences', 2);
});

test('category revisions preserve snapshots and allow explicit same-family matching across flag versions', function () {
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $old = B::category($owner);
    $rule = B::rule($owner, $old);
    $new = app(BudgetService::class)->category($owner, 'revise-category-01', [...B::categoryInput(), 'normal_budget' => '0', 'emergency_essential' => '1', 'reason' => 'Synthetic new flags'], (string) $old->id);
    $journal = app(PostingService::class)->record($owner, 'versioned-actual', ['kind' => 'expense', 'account_id' => (string) $account->id, 'amount' => '147.25', 'effective_date' => '2026-10-18', 'description' => 'Synthetic category receipt']);
    app(BudgetService::class)->attribute($owner, 'attribute-new-01', (string) $journal->id, (string) $new->id, 'Explicit selected version');
    app(BudgetService::class)->allocate($owner, 'match-old-family', (string) $rule->id, '2026-10-18', (string) $journal->id, '147.25');
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['occurrences'][0]['category']['normal_budget'])->toBeTrue()->and($view['activity'][0]['normal_budget'])->toBeFalse()
        ->and($view['occurrences'][0]['status'])->toBe('matched')->and($new->family_reference)->toBe($old->family_reference);
    $other = B::category($owner, ['name' => 'Other category']);
    expect(fn () => app(BudgetService::class)->attribute($owner, 'blocked-family-01', (string) $journal->id, (string) $other->id, 'Change family'))->toThrow(ValidationException::class);
});

test('budget activity excludes transfer opening FX principal and equity while categorized FX fee is expense once', function () {
    $owner = User::factory()->create();
    $source = Fx::account($owner);
    $destination = Fx::account($owner, Currency::NGN);
    $category = B::category($owner);
    $fx = app(FxService::class)->record($owner, 'budget-fx-00001', Fx::input($source, $destination));
    $fee = DB::table('fx_operation_journals')->where('operation_id', $fx->id)->where('role', 'fee')->value('journal_id');
    app(BudgetService::class)->attribute($owner, 'classify-fx-fee', (string) $fee, (string) $category->id, 'Synthetic explicit fee mapping');
    $other = Fx::account($owner);
    app(PostingService::class)->record($owner, 'excluded-transfer', ['kind' => 'transfer', 'account_id' => (string) $source->id, 'destination_account_id' => (string) $other->id, 'amount' => '1.00', 'effective_date' => '2026-10-18', 'description' => 'Synthetic internal movement']);
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['activity'])->toHaveCount(1)->and($view['totals'][0]['actual_expense']['minor'])->toBe('250');
    $principal = DB::table('fx_operation_journals')->where('operation_id', $fx->id)->where('role', 'source')->value('journal_id');
    expect(fn () => app(BudgetService::class)->attribute($owner, 'principal-denied', (string) $principal, (string) $category->id, 'Not an expense'))->toThrow(ValidationException::class);
});

test('Qatar today and salary rule day remain distinct from UTC date-only actual periods', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31T21:30:00Z'));
    expect(BudgetCalendar::today())->toBe('2026-11-01');
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $category = B::category($owner, ['direction' => 'income']);
    $rule = B::rule($owner, $category, ['salary' => '1', 'first_due_date' => '2026-11-01']);
    $journal = app(PostingService::class)->record($owner, 'boundary-receipt', ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '147.25', 'effective_date' => '2026-10-31', 'description' => 'Synthetic UTC-date receipt']);
    app(BudgetService::class)->allocate($owner, 'boundary-match-01', (string) $rule->id, '2026-11-01', (string) $journal->id, '147.25');
    expect(app(BudgetView::class)->month($owner, '2026-10')['totals'][0]['actual_income']['minor'])->toBe('14725')
        ->and(app(BudgetView::class)->month($owner, '2026-11')['totals'][0]['actual_income']['minor'])->toBe('0');
});

test('large plan aggregates expose unavailable totals and comparisons rather than overflow or false variance', function () {
    $owner = User::factory()->create();
    $category = B::category($owner);
    B::rule($owner, $category, ['amount' => '92233720368547758.07']);
    B::rule($owner, $category, ['amount' => '0.01']);
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['totals'][0]['planned_expense']['available'])->toBeFalse()->and($view['comparisons'][0]['planned']['reason'])->toBe('out_of_range')
        ->and($view['comparisons'][0]['variance']['available'])->toBeFalse();
});

test('essential run-rate is explicitly month-end and future annual provision starts on its configured date', function () {
    $owner = User::factory()->create();
    $cat = B::category($owner, ['emergency_essential' => '1']);
    B::rule($owner, $cat, ['end_date' => '2026-10-20']);
    B::rule($owner, $cat, ['amount' => '120.01', 'cadence' => 'annual', 'first_due_date' => '2027-01-31', 'provision_start_date' => '2026-11-01']);
    $oct = app(BudgetView::class)->month($owner, '2026-10');
    $nov = app(BudgetView::class)->month($owner, '2026-11');
    expect($oct['totals'][0]['planned_expense']['minor'])->toBe('14725')->and($oct['totals'][0]['essential_monthly']['minor'])->toBe('0')
        ->and($oct['provisions'])->toBeEmpty()->and($nov['occurrences'])->toBeEmpty()->and($nov['provisions'][0]['amount']['minor'])->toBe('1001')
        ->and($nov['totals'][0]['essential_monthly']['minor'])->toBe('1001');
});

test('legacy original and cancellation periods become unavailable while an intervening replacement month stays exact', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-18T12:00:00Z'));
    $owner = User::factory()->create();
    $account = Fx::account($owner);
    $cat = B::category($owner);
    $original = app(PostingService::class)->record($owner, 'budget-legacy-original', ['kind' => 'expense', 'account_id' => (string) $account->id, 'amount' => '17.35', 'effective_date' => '2026-01-15', 'description' => 'Synthetic legacy expense']);
    app(BudgetService::class)->attribute($owner, 'budget-legacy-category', (string) $original->id, (string) $cat->id, 'Explicit original mapping');
    app(PostingService::class)->reverse($owner, (string) $original->id, 'budget-legacy-cancel', 'Synthetic retained later cancellation');
    // Exact accepted legacy shape, all constraints enabled; no immutable row updates.
    $replacement = DB::transaction(function () use ($original) {
        $copy = (array) DB::table('journal_entries')->find($original->id);
        unset($copy['id']);
        $copy['reference'] = (string) Str::ulid();
        $copy['idempotency_key'] = 'budget-legacy-replacement';
        $copy['payload_hash'] = str_repeat('0', 64);
        $copy['request_payload'] = '{}';
        $copy['replaces_journal_id'] = $original->id;
        $copy['effective_date'] = '2026-02-15';
        $copy['status'] = 'pending';
        $copy['posted_at'] = null;
        $id = DB::table('journal_entries')->insertGetId($copy);
        foreach ($original->postings as $leg) {
            DB::table('postings')->insert(['journal_id' => $id, 'owner_id' => $original->owner_id, 'account_id' => $leg->account_id, 'currency_code' => $leg->currency_code->value, 'amount_minor' => $leg->amount_minor]);
        }
        DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);

        return $id;
    });
    app(BudgetService::class)->attribute($owner, 'budget-legacy-new-category', (string) $replacement, (string) $cat->id, 'Explicit replacement mapping');
    // The legacy component is outside the visible first page in both affected months.
    foreach (['2026-01-20', '2026-03-17'] as $date) {
        for ($i = 0; $i < 21; $i++) {
            app(PostingService::class)->record($owner, 'budget-newer-'.$date.'-'.$i, ['kind' => 'expense', 'account_id' => (string) $account->id, 'amount' => '1.00', 'effective_date' => $date, 'description' => 'Synthetic newer activity']);
        }
    }
    $before = DB::table('journal_entries')->orderBy('id')->get()->toJson();
    foreach (['2026-01', '2026-03'] as $month) {
        $view = app(BudgetView::class)->month($owner, $month);
        expect($view['totals'][0]['actual_expense']['minor'])->toBeNull()->and($view['comparisons'][0]['actual']['reason'])->toBe('legacy_correction_timing')
            ->and($view['comparisons'][0]['variance']['available'])->toBeFalse()->and($view['activity'])->toHaveCount(20)
            ->and(array_column($view['activity'], 'id'))->not->toContain((string) $original->id);
    }
    $feb = app(BudgetView::class)->month($owner, '2026-02');
    expect($feb['totals'][0]['actual_expense']['minor'])->toBe('1735')->and($feb['comparisons'][0]['variance']['minor'])->toBe('1735')
        ->and(DB::table('journal_entries')->orderBy('id')->get()->toJson())->toBe($before);
});

test('a successful future revision recovers next day before temporal revalidation', function () {
    $owner = User::factory()->create();
    $cat = B::category($owner);
    $rule = B::rule($owner, $cat);
    $input = B::input($cat, ['first_due_date' => '2026-10-19', 'cutover_date' => '2026-10-19', 'reason' => 'Synthetic future revision']);
    $first = app(BudgetService::class)->schedule($owner, 'budget-tomorrow-retry', $input, (string) $rule->id);
    $this->travelTo(CarbonImmutable::parse('2026-10-20T12:00:00Z'));
    expect(app(BudgetService::class)->schedule($owner, 'budget-tomorrow-retry', $input, (string) $rule->id)->id)->toBe($first->id);
    $this->assertDatabaseCount('planning_schedules', 2);
});

test('zero and three decimal currencies allocate exactly without cross currency netting', function (Currency $currency, string $amount, string $minor) {
    $owner = User::factory()->create();
    $cat = B::category($owner);
    $account = Fx::account($owner, $currency);
    $rule = B::rule($owner, $cat, ['currency' => $currency->value, 'amount' => $amount]);
    $journal = app(PostingService::class)->record($owner, 'budget-exponent-receipt', ['kind' => 'expense', 'account_id' => (string) $account->id, 'amount' => $amount, 'effective_date' => '2026-10-18', 'description' => 'Synthetic exact exponent']);
    app(BudgetService::class)->allocate($owner, 'budget-exponent-match', (string) $rule->id, '2026-10-18', (string) $journal->id, $amount);
    $view = app(BudgetView::class)->month($owner, '2026-10');
    expect($view['occurrences'][0]['matched']['minor'])->toBe($minor)->and($view['totals'][0]['actual_expense']['currency'])->toBe($currency->value);
})->with([[Currency::JPY, '1735', '1735'], [Currency::KWD, '17.359', '17359']]);
