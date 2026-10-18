<?php

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\PostingService;
use App\Domain\Planning\CashAvailability;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AvailabilityFixtures as A;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
});
test('financial totals include all accounts reservations and bills beyond the twenty-row disclosures', function () {
    for ($i = 0; $i < 24; $i++) {
        $a = Fx::account($this->owner);
        G::cash($this->owner, $a, '100');
        G::goal($this->owner, $a, ['opening' => '10', 'contribution' => '0']);
    }
    $category = B::category($this->owner);
    $requirements = [];
    for ($i = 0; $i < 24; $i++) {
        $bill = B::rule($this->owner, $category, ['amount' => '1', 'cadence' => 'once', 'first_due_date' => '2026-10-22']);
        $requirements[] = ['schedule_id' => (string) $bill->id, 'required' => '1'];
    }
    A::settings($this->owner, ['buffer' => '0', 'requirements' => $requirements]);
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['account_count'])->toBe(24)->and($r['accounts'])->toHaveCount(20)->and($r['occurrence_count'])->toBe(24)->and($r['occurrences'])->toHaveCount(20)
        ->and($r['actual']['raw']['minor'])->toBe('213600')->and($r['required_choices'])->toHaveCount(20)->and($r['required_next_cursor'])->not->toBeNull()
        ->and(count($queries))->toBeLessThan(20);
    $next = app(CashAvailability::class)->capture($this->owner, $r['required_next_cursor']);
    expect($next['required_choices'])->toHaveCount(4)->and($next['actual']['raw'])->toBe($r['actual']['raw']);
    $this->actingAs($this->owner)->get('/cash/settings')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('goals', 20)->has('reservations', 20)->has('cash.required_choices', 20)->where('settings_history.0.version', 1));
});
test('missing unclassified families beyond the first page keep the entire result incomplete', function () {
    $category = B::category($this->owner);
    $requirements = [];
    for ($i = 0; $i < 21; $i++) {
        $bill = B::rule($this->owner, $category, ['amount' => '1']);
        if ($i < 20) {
            $requirements[] = ['schedule_id' => (string) $bill->id, 'required' => '0'];
        }
    }
    A::settings($this->owner, ['requirements' => $requirements]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['required_choices'])->toHaveCount(20)->and($r['unclassified_count'])->toBe(1)->and($r['actual']['deployable'])->toBeNull();
});
test('fully protected foreign accounts use one native grouping before rounding', function () {
    for ($i = 0; $i < 2; $i++) {
        $a = Fx::account($this->owner, Currency::USD);
        G::cash($this->owner, $a, '0.01');
        G::goal($this->owner, $a, ['opening' => '0.01', 'target' => '1', 'contribution' => '0']);
    }
    app(FxObservationService::class)->record($this->owner, 'cash-group-round-rate', Fx::quote('USD', 'QAR', '0.5'));
    A::settings($this->owner, ['buffer' => '0']);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['state'])->toBe('complete')->and($r['actual']['raw']['minor'])->toBe('0')->and($r['actual']['breakdown']['cash']['minor'])->toBe('1')->and($r['actual']['breakdown']['protected']['minor'])->toBe('1');
});
test('seven-day reporting boundary is inclusive then becomes explicitly stale', function () {
    $a = Fx::account($this->owner, Currency::USD);
    G::cash($this->owner, $a, '100');
    app(FxObservationService::class)->record($this->owner, 'cash-seven-day-rate', [...Fx::quote('USD', 'QAR', '2'), 'observed_at' => '2026-10-11T12:00:00Z', 'effective_at' => '2026-10-11T12:00:00Z']);
    A::settings($this->owner, ['buffer' => '0']);
    expect(app(CashAvailability::class)->capture($this->owner)['actual']['raw']['minor'])->toBe('20000');
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:01Z'));
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['state'])->toBe('incomplete')->and($r['actual']['missing'])->toContain('USD:stale_fx')->and($r['actual']['deployable'])->toBeNull();
});
test('foreign-owner reciprocal and future quotes cannot replace the required current direct observation', function () {
    $a = Fx::account($this->owner, Currency::USD);
    G::cash($this->owner, $a, '100');
    $other = User::factory()->create();
    app(FxObservationService::class)->record($other, 'cash-foreign-rate', Fx::quote('USD', 'QAR', '2'));
    app(FxObservationService::class)->record($this->owner, 'cash-reciprocal-rate', Fx::quote('QAR', 'USD', '0.5'));
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    app(FxObservationService::class)->record($this->owner, 'cash-future-observation', [...Fx::quote('USD', 'QAR', '2'), 'observed_at' => '2026-10-19T00:00:00Z', 'effective_at' => '2026-10-19T00:00:00Z']);
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    A::settings($this->owner, ['buffer' => '0']);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['missing'])->toContain('USD:missing_fx')->and($r['actual']['raw'])->toBeNull();
});
test('pending openings and excluded nonliquid wealth cannot invent spendable cash', function () {
    app(CreateAccount::class)->create($this->owner, 'Synthetic unposted opening', AccountType::Bank, Currency::QAR, Money::fromDecimal(Currency::QAR, '100'));
    $investment = app(CreateAccount::class)->create($this->owner, 'Synthetic aggregate wealth', AccountType::Investment, Currency::QAR, null);
    G::cash($this->owner, $investment, '1000');
    A::settings($this->owner, ['buffer' => '0']);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['account_count'])->toBe(1)->and($r['actual']['missing'])->toContain('QAR:opening_pending')->and($r['actual']['deployable'])->toBeNull();
});
test('future effective cash is excluded while present posted cash is counted once', function () {
    $a = Fx::account($this->owner);
    G::cash($this->owner, $a, '100');
    $this->travelTo(CarbonImmutable::parse('2026-11-01T12:00:00Z'));
    app(PostingService::class)->record($this->owner, 'cash-future-posted-income', ['kind' => 'income', 'account_id' => (string) $a->id, 'amount' => '900', 'effective_date' => '2026-11-01', 'description' => 'Synthetic future dated income']);
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    A::settings($this->owner, ['buffer' => '0']);
    expect(app(CashAvailability::class)->capture($this->owner)['actual']['raw']['minor'])->toBe('10000');
});
test('an unavailable account range cannot be hidden by a second account offset', function () {
    $a = Fx::account($this->owner);
    $b = Fx::account($this->owner);
    $max = '92233720368547758.07';
    G::cash($this->owner, $a, $max);
    G::cash($this->owner, $a, '1');
    app(PostingService::class)->record($this->owner, 'cash-range-offset', ['kind' => 'expense', 'account_id' => (string) $b->id, 'amount' => '1', 'effective_date' => '2026-10-18', 'description' => 'Synthetic offset']);
    A::settings($this->owner, ['buffer' => '0']);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['state'])->toBe('incomplete')->and($r['actual']['missing'])->toContain('QAR:out_of_range')->and($r['actual']['raw'])->toBeNull();
});
test('recurrence evaluation limit gives an incomplete unknown count instead of truncated zero bills', function () {
    $category = B::category($this->owner);
    $requirements = [];
    for ($i = 0; $i < 5; $i++) {
        $rule = B::rule($this->owner, $category, ['amount' => '1', 'first_due_date' => '0001-01-31']);
        $requirements[] = ['schedule_id' => (string) $rule->id, 'required' => '1'];
    }
    A::settings($this->owner, ['requirements' => $requirements]);
    $r = app(CashAvailability::class)->capture($this->owner);
    expect($r['actual']['missing'])->toContain('projection_range_exceeded')->and($r['actual']['deployable'])->toBeNull()->and($r['occurrence_count'])->toBeNull();
});

test('ended ancient rules enumerate only candidates admitted by their own end bound', function () {
    $category = B::category($this->owner);
    $requirements = [];
    for ($i = 0; $i < 5; $i++) {
        $rule = B::rule($this->owner, $category, ['amount' => '1', 'first_due_date' => '0001-01-31', 'end_date' => '0001-01-31']);
        $requirements[] = ['schedule_id' => (string) $rule->id, 'required' => '1'];
    }
    $future = B::rule($this->owner, $category, ['amount' => '1', 'cadence' => 'once', 'first_due_date' => '2027-01-01']);
    $requirements[] = ['schedule_id' => (string) $future->id, 'required' => '1'];
    A::settings($this->owner, ['buffer' => '0', 'requirements' => $requirements]);
    $captured = null;
    DB::listen(function ($query) use (&$captured) {
        if (str_contains($query->sql, 'generate_series')) {
            $captured = [$query->sql, $query->bindings];
        }
    });
    $snapshot = app(CashAvailability::class)->capture($this->owner);
    expect($snapshot['actual']['state'])->toBe('complete')->and($snapshot['occurrence_count'])->toBe(5)
        ->and($snapshot['actual']['breakdown']['unfunded']['minor'])->toBe('500');
    expect($captured)->not->toBeNull();
    $result = DB::select('EXPLAIN (ANALYZE, FORMAT JSON) '.$captured[0], $captured[1]);
    $plan = json_decode($result[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
    $enumerated = 0;
    $walk = function (array $node) use (&$walk, &$enumerated): void {
        if (($node['Node Type'] ?? '') === 'Function Scan' && ($node['Alias'] ?? '') === 'n') {
            $enumerated += (($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0)) * $node['Actual Loops'];
        }
        foreach ($node['Plans'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($plan[0]['Plan']);
    // EXPLAIN reports rounded per-loop averages (for example 0.83 x 6).
    expect((int) round($enumerated))->toBe(5);
});
