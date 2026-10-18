<?php

use App\Domain\Ledger\FinancialSnapshot;
use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\PostingService;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PortfolioService;
use App\Domain\Reporting\NetWorthSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->account = P::account($this->owner);
    $this->portfolio = P::link($this->owner, $this->account);
});
test('fractional opening units and prices survive exact PostgreSQL storage without cash rounding', function () {
    $a = P::asset($this->owner);
    P::opening($this->owner, $this->portfolio, [P::row($a)]);
    $row = app(PortfolioRead::class)->state($this->owner, (string) $this->portfolio->id, '2026-10-18T12:00:00Z')['rows'][0];
    expect($row['quantity'])->toBe('1.234567890123')->and($row['unit_price'])->toBe('2.000000000001')->and($row['cost_basis'])->toBeNull();
});
test('one mode selects cash plus positions or whole account value never both', function () {
    app(PostingService::class)->record($this->owner, 'portfolio-cash-income', ['kind' => 'income', 'account_id' => (string) $this->account->id, 'amount' => '10', 'effective_date' => '2026-10-18', 'description' => 'Synthetic cash']);
    P::opening($this->owner, $this->portfolio, [P::row(P::asset($this->owner), ['quantity' => '3', 'unit_price' => '10'])]);
    P::mode($this->owner, $this->portfolio);
    $s = app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z');
    expect($s['total']['minor'])->toBe('4000')->and($s['components'][0]['portfolio']['selected'])->toBe(['ledger_cash', 'opening_positions', 'paid_ipo_receivables']);
    P::mode($this->owner, $this->portfolio, ['mode' => 'aggregate', 'aggregate_value' => '50', 'aggregate_as_of' => '2026-10-18']);
    $s = app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z');
    expect($s['total']['minor'])->toBe('5000')->and($s['components'][0]['ledger_cash']['minor'])->toBe('1000')
        ->and($s['components'][0]['portfolio']['excluded'])->toBe(['ledger_cash', 'opening_positions', 'paid_ipo_receivables']);
    expect(app(FinancialSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z')['total']['minor'])->toBe('1000');
});
test('unknown basis differs from explicit known zero and never fabricates profit', function () {
    $a = P::asset($this->owner);
    $b = P::asset($this->owner, ['name' => 'Synthetic Zero Basis']);
    P::opening($this->owner, $this->portfolio, [P::row($a), P::row($b, ['basis_known' => '1', 'cost_basis' => '0'])]);
    $r = app(PortfolioRead::class)->state($this->owner, (string) $this->portfolio->id, '2026-10-18T12:00:00Z')['rows'];
    expect($r[0]['basis_known'])->toBeFalse()->and($r[0]['cost_basis'])->toBeNull()->and($r[1]['basis_known'])->toBeTrue()->and($r[1]['cost_basis']['minor'])->toBe('0');
    expect(array_key_exists('gain', $r[0]))->toBeFalse();
});
test('mode requires a reviewed preview and exact committed retry wins after expiry and later changes', function () {
    $service = app(PortfolioService::class);
    $preview = $service->preview($this->owner, (string) $this->portfolio->id, P::modeInput());
    expect(DB::table('portfolio_modes')->count())->toBe(0)->and(DB::table('journal_entries')->count())->toBe(0);
    $first = $service->confirm($this->owner, (string) $this->portfolio->id, 'portfolio-confirm-retry', (string) $preview->id, '1');
    $this->travel(11)->minutes();
    expect($service->confirm($this->owner, (string) $this->portfolio->id, 'portfolio-confirm-retry', (string) $preview->id, '1')->id)->toBe($first->id);
    expect(fn () => $service->confirm($this->owner, (string) $this->portfolio->id, 'portfolio-other-request', (string) $preview->id, '1'))->toThrow(ValidationException::class);
    expect(DB::table('portfolio_modes')->count())->toBe(1);
});
test('source changes stale a mode preview including posted cash and a UTC day change', function () {
    $service = app(PortfolioService::class);
    $preview = $service->preview($this->owner, (string) $this->portfolio->id, P::modeInput());
    app(PostingService::class)->record($this->owner, 'portfolio-preview-cash', ['kind' => 'income', 'account_id' => (string) $this->account->id, 'amount' => '1', 'effective_date' => '2026-10-18', 'description' => 'Synthetic change']);
    expect(fn () => $service->confirm($this->owner, (string) $this->portfolio->id, 'portfolio-stale-cash', (string) $preview->id, '1'))->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-18T23:59:00Z'));
    $preview = $service->preview($this->owner, (string) $this->portfolio->id, P::modeInput());
    $this->travelTo(CarbonImmutable::parse('2026-10-19T00:00:01Z'));
    expect(fn () => $service->confirm($this->owner, (string) $this->portfolio->id, 'portfolio-stale-day', (string) $preview->id, '1'))->toThrow(ValidationException::class);
    expect(DB::table('portfolio_modes')->count())->toBe(0);
});
test('missing and older opening prices are unavailable rather than zero or cost basis', function () {
    P::mode($this->owner, $this->portfolio);
    P::opening($this->owner, $this->portfolio, [P::row(P::asset($this->owner), ['unit_price' => '', 'basis_known' => '1', 'cost_basis' => '100'])]);
    $s = app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z');
    expect($s['total'])->toBeNull()->and($s['components'][0]['portfolio']['missing'])->not->toBeEmpty();
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    expect(app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-19T12:00:00Z')['total'])->toBeNull();
});
test('opening corrections retain original evidence and do not add duplicate holdings', function () {
    $a = P::asset($this->owner);
    $first = P::opening($this->owner, $this->portfolio, [P::row($a)]);
    P::opening($this->owner, $this->portfolio, [P::row($a, ['quantity' => '2'])], ['prior_id' => (string) $first->id]);
    $state = app(PortfolioRead::class)->state($this->owner, (string) $this->portfolio->id, '2026-10-18T12:00:00Z');
    expect($state['rows'])->toHaveCount(1)->and($state['rows'][0]['quantity'])->toBe('2')->and(DB::table('portfolio_opening_rows')->count())->toBe(2)->and(DB::table('journal_entries')->count())->toBe(0);
    expect(fn () => P::opening($this->owner, $this->portfolio, [], ['prior_id' => (string) $state['opening']['id'], 'as_of' => '2026-10-17']))->toThrow(ValidationException::class);
});
test('manual investments retain their explicit value and unsupported terms without invented units', function () {
    $a = P::asset($this->owner, ['kind' => 'manual_investment', 'unsupported_terms' => 'Complex cashflows are not modeled']);
    P::opening($this->owner, $this->portfolio, [P::row($a, ['quantity' => '', 'unit_price' => '', 'manual_value' => '12.34'])]);
    P::mode($this->owner, $this->portfolio);
    $s = app(PortfolioRead::class)->state($this->owner, (string) $this->portfolio->id, '2026-10-18T12:00:00Z');
    expect($s['rows'][0]['quantity'])->toBeNull()->and($s['rows'][0]['unsupported_terms'])->toBe('Complex cashflows are not modeled')->and($s['contribution']['value']['minor'])->toBe('1234');
});
test('owner and administrator cannot reference another owners portfolio or asset', function () {
    $other = User::factory()->create(['is_admin' => true]);
    $a = P::asset($other);
    expect(fn () => P::opening($this->owner, $this->portfolio, [P::row($a)]))->toThrow(NotFoundHttpException::class);
    expect(fn () => app(PortfolioService::class)->preview($other, (string) $this->portfolio->id, P::modeInput()))->toThrow(NotFoundHttpException::class);
    expect(DB::table('portfolio_openings')->count())->toBe(0);
});
test('legacy investment accounts retain actionable missing and stale FX states', function () {
    $owner = User::factory()->create();
    P::account($owner, Currency::USD);
    expect(app(NetWorthSnapshot::class)->capture($owner, Currency::QAR, '2026-10-18T12:00:00Z')['state'])->toBe('missing');
    app(FxObservationService::class)->record($owner, 'portfolio-old-rate', [...Fx::quote('USD', 'QAR', '2'), 'observed_at' => '2026-10-01T00:00:00Z', 'effective_at' => '2026-10-01T00:00:00Z']);
    expect(app(NetWorthSnapshot::class)->capture($owner, Currency::QAR, '2026-10-18T12:00:00Z')['state'])->toBe('stale');
});
