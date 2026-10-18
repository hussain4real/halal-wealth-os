<?php

use App\Domain\Ledger\PostingService;
use App\Domain\Portfolio\InvestmentService;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PortfolioService;
use App\Domain\Reporting\NetWorthSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->account = P::account($this->owner);
    $this->p = P::link($this->owner, $this->account);
    $this->asset = P::asset($this->owner);
    I::fund($this->owner, $this->account);
});
test('synthetic fee inclusive purchase and partial sale preserve weighted basis and net cash separately', function () {
    I::event($this->owner, $this->p, $this->asset);
    $sale = I::event($this->owner, $this->p, $this->asset, ['kind' => 'sell', 'quantity' => '3', 'unit_price' => '8', 'amount' => '24', 'fee' => '0.03']);
    $r = I::replay($this->owner, $this->p);
    expect($r['rows'][0]['quantity'])->toBe('5')->and($r['rows'][0]['cost_basis']['minor'])->toBe('3505')
        ->and($r['events'][1]['allocated_cost_minor'])->toBe('2103')->and($r['events'][1]['realized_gain_minor'])->toBe('294');
    $state = app(PortfolioRead::class)->state($this->owner, (string) $this->p->id, '2026-10-18T12:00:00Z');
    expect($state['ledger_cash']['minor'])->toBe('196789')->and($state['rows'][0]['unit_price'])->toBeNull();
    expect(DB::table('investment_event_journals')->where('event_id', $sale->id)->exists())->toBeTrue();
});
test('moving pools combine metadata versions and preserve exact fractional units', function () {
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '0.123456789012', 'unit_price' => '1.234567890123', 'amount' => '3.01', 'fee' => '0']);
    $next = P::asset($this->owner, ['prior_id' => (string) $this->asset->id, 'name' => 'Synthetic revised label']);
    I::event($this->owner, $this->p, $next, ['quantity' => '0.000000000001', 'amount' => '2.02', 'fee' => '0']);
    $r = I::replay($this->owner, $this->p);
    expect($r['rows'])->toHaveCount(1)->and($r['rows'][0]['quantity'])->toBe('0.123456789013')->and($r['rows'][0]['cost_basis']['minor'])->toBe('503');
});
test('final sale consumes every remaining basis minor without an average price rounding truth', function () {
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '3', 'amount' => '1.01', 'fee' => '0']);
    foreach (range(1, 3) as $n) {
        I::event($this->owner, $this->p, $this->asset, ['kind' => 'sell', 'quantity' => '1', 'amount' => '1', 'unit_price' => '1']);
    }
    $r = I::replay($this->owner, $this->p);
    expect($r['rows'])->toBeEmpty()->and(array_column(array_slice($r['events'], 1), 'allocated_cost_minor'))->toBe(['34', '34', '33']);
});
test('unknown basis persists through mixed acquisitions and splits then resets only after full closure', function () {
    P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '2', 'unit_price' => '1'])]);
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '1', 'amount' => '5', 'fee' => '0']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'split']);
    expect(I::replay($this->owner, $this->p)['rows'][0]['cost_basis'])->toBeNull();
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'sell', 'quantity' => '6', 'amount' => '20']);
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '1', 'amount' => '4', 'fee' => '0']);
    $r = I::replay($this->owner, $this->p);
    expect($r['rows'][0]['basis_known'])->toBeTrue()->and($r['rows'][0]['cost_basis']['minor'])->toBe('400')->and($r['realized_gain'])->toBeNull();
});
test('split preserves cost applies one business action once and never turns execution price into a quote', function () {
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '3', 'amount' => '27.03', 'fee' => '0']);
    $input = I::input($this->asset, ['kind' => 'split']);
    $first = app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'synthetic-split-retry', $input);
    expect(app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'synthetic-split-retry', $input)->id)->toBe($first->id);
    expect(fn () => app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'synthetic-split-other-key', $input))->toThrow(ValidationException::class);
    $r = I::replay($this->owner, $this->p);
    expect($r['rows'][0]['quantity'])->toBe('6')->and($r['rows'][0]['cost_basis']['minor'])->toBe('2703')->and($r['rows'][0]['unit_price'])->toBeNull();
    $this->assertDatabaseCount('investment_event_journals', 1);
});
test('fully earmarked IPO payment consumes its own claim and confirmed allotment refund clear receivable', function () {
    $p = P::link($this->owner, $a = P::account($this->owner));
    I::fund($this->owner, $a, '63');
    $intent = I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '63']);
    $child = ['subscription_id' => (string) $intent->id, 'amount' => '63'];
    I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_reserve', ...$child]);
    expect(I::replay($this->owner, $p)['rows'])->toBeEmpty();
    I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_pay', ...$child]);
    $paid = I::replay($this->owner, $p);
    expect($paid['receivable_minor'])->toBe('6300')->and($paid['reserved_minor'])->toBe('0')->and($paid['rows'])->toBeEmpty();
    I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_allot', ...$child, 'amount' => '48', 'quantity' => '12', 'unit_price' => '4']);
    I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_refund', ...$child, 'amount' => '15']);
    $r = I::replay($this->owner, $p);
    expect($r['receivable_minor'])->toBe('0')->and($r['rows'][0]['quantity'])->toBe('12')->and($r['rows'][0]['cost_basis']['minor'])->toBe('4800');
    expect(app(PortfolioRead::class)->state($this->owner, (string) $p->id, '2026-10-18T12:00:00Z')['ledger_cash']['minor'])->toBe('1500');
});
test('receivable is added once in detailed mode and aggregate must be reviewed after new facts', function () {
    P::mode($this->owner, $this->p);
    $intent = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '63']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_pay', 'amount' => '63', 'subscription_id' => (string) $intent->id]);
    $s = app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z');
    expect($s['total']['minor'])->toBe('200000')->and($s['components'][0]['ledger_cash']['minor'])->toBe('193700');
    P::mode($this->owner, $this->p, ['mode' => 'aggregate', 'aggregate_value' => '2500', 'aggregate_as_of' => '2026-10-18']);
    expect(app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z')['total']['minor'])->toBe('250000');
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_refund', 'amount' => '1', 'subscription_id' => (string) $intent->id]);
    expect(app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z')['total'])->toBeNull();
});
test('partial refund before allotment is permitted but overclearing unconfirmed shares and paid cancellation are rejected', function () {
    $intent = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '63']);
    $s = ['subscription_id' => (string) $intent->id];
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_pay', 'amount' => '63', ...$s]);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_refund', 'amount' => '15', ...$s]);
    foreach ([['kind' => 'ipo_refund', 'amount' => '49'], ['kind' => 'ipo_allot', 'amount' => '48', 'confirmed' => ''], ['kind' => 'ipo_cancel']] as $bad) {
        expect(fn () => I::event($this->owner, $this->p, $this->asset, [...$s, ...$bad]))->toThrow(ValidationException::class);
    }
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_allot', 'amount' => '48', 'quantity' => '4', ...$s]);
    expect(I::replay($this->owner, $this->p)['receivable_minor'])->toBe('0');
});
test('unpaid cancellation releases earmark without journal or invented refund', function () {
    $intent = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '63']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_reserve', 'amount' => '63', 'subscription_id' => (string) $intent->id]);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_cancel', 'subscription_id' => (string) $intent->id]);
    expect(I::replay($this->owner, $this->p)['reserved_minor'])->toBe('0');
    $this->assertDatabaseCount('investment_event_journals', 0);
});
test('all IPO earmarks protect investment cash against other purchases and payments', function () {
    $intent = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '1990']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_reserve', 'amount' => '1990', 'subscription_id' => (string) $intent->id]);
    expect(fn () => I::event($this->owner, $this->p, $this->asset))->toThrow(ValidationException::class);
    expect(I::replay($this->owner, $this->p)['reserved_minor'])->toBe('199000');
});
test('an underfunded retained earmark can be released or cancelled without inventing replacement cash', function () {
    $intent = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '2000']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_reserve', 'amount' => '2000', 'subscription_id' => (string) $intent->id]);
    app(PostingService::class)->record($this->owner, 'synthetic-external-cash-use', ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '1000', 'effective_date' => '2026-10-18', 'description' => 'Synthetic later actual cash use']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_release', 'amount' => '1000', 'subscription_id' => (string) $intent->id]);
    expect(I::replay($this->owner, $this->p)['reserved_minor'])->toBe('100000');
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_cancel', 'subscription_id' => (string) $intent->id]);
    expect(I::replay($this->owner, $this->p)['reserved_minor'])->toBe('0');
    $this->assertDatabaseCount('investment_event_journals', 0);
    I::event($this->owner, $this->p, $this->asset);
    expect(I::replay($this->owner, $this->p)['rows'][0]['quantity'])->toBe('8');
    expect(app(PortfolioRead::class)->state($this->owner, (string) $this->p->id, '2026-10-18T12:00:00Z')['ledger_cash']['minor'])->toBe('94392');
});
test('dividend retains gross deductions and net receipt without creating shares or changing basis', function () {
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'dividend', 'amount' => '11', 'fee' => '0.15', 'tax' => '0.85']);
    $r = I::replay($this->owner, $this->p);
    expect($r['rows'])->toBeEmpty()->and($r['events'][0]['cash_minor'])->toBe('1000');
    expect(DB::table('investment_events')->sole()->tax_minor)->toBe(85);
    expect(app(PortfolioRead::class)->state($this->owner, (string) $this->p->id, '2026-10-18T12:00:00Z')['ledger_cash']['minor'])->toBe('201000');
});
test('oversell and invalid chronological prefixes roll back both event and journal', function () {
    I::event($this->owner, $this->p, $this->asset, ['effective_date' => '2026-10-18']);
    $before = DB::table('journal_entries')->count();
    foreach ([['quantity' => '8.000000000001'], ['quantity' => '1', 'effective_date' => '2026-10-17']] as $bad) {
        expect(fn () => I::event($this->owner, $this->p, $this->asset, ['kind' => 'sell', 'amount' => '1', ...$bad]))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('investment_events', 1);
    $this->assertDatabaseCount('journal_entries', $before);
});
test('later cash deposits cannot fund a backdated investment outflow', function () {
    expect(fn () => I::event($this->owner, $this->p, $this->asset, ['effective_date' => '2026-10-17']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('investment_events', 0);
    $this->assertDatabaseCount('journal_entries', 1);
});
test('atomic correction inherits ordering and recomputes later sale book cost', function () {
    $input = I::input($this->asset);
    $buy = app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'synthetic-correct-buy', $input);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'sell', 'quantity' => '3', 'amount' => '24']);
    expect(fn () => app(InvestmentService::class)->reverse($this->owner, (string) $this->p->id, (string) $buy->id, 'synthetic-invalid-reverse', 'Synthetic dependent history'))->toThrow(ValidationException::class);
    $next = app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'synthetic-correct-replacement', [...$input, 'amount' => '64', 'replaces_id' => (string) $buy->id]);
    $r = I::replay($this->owner, $this->p);
    expect($next->sort_id)->toBe($buy->sort_id)->and($r['rows'][0]['cost_basis']['minor'])->toBe('4005')->and($r['events'][1]['allocated_cost_minor'])->toBe('2403');
    expect(DB::table('investment_events')->count())->toBe(4);
});
test('opening corrections replay downstream holdings and refuse an invalid replacement atomically', function () {
    $book = P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '5', 'basis_known' => '1', 'cost_basis' => '5'])]);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'sell', 'quantity' => '4', 'amount' => '6']);
    expect(fn () => P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '3'])], ['prior_id' => (string) $book->id]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('portfolio_openings', 1);
});
test('generic ledger reversal and correction cannot detach investment cash from holdings', function () {
    $event = I::event($this->owner, $this->p, $this->asset);
    $journal = (string) DB::table('investment_event_journals')->where('event_id', $event->id)->value('journal_id');
    expect(fn () => app(PostingService::class)->reverse($this->owner, $journal, 'synthetic-generic-reverse', 'Synthetic forbidden member'))->toThrow(ValidationException::class);
    expect(fn () => app(PostingService::class)->correct($this->owner, $journal, 'synthetic-generic-correct', []))->toThrow(ValidationException::class);
    app(InvestmentService::class)->reverse($this->owner, (string) $this->p->id, (string) $event->id, 'synthetic-linked-reverse', 'Synthetic complete cancellation');
    expect(I::replay($this->owner, $this->p)['rows'])->toBeEmpty();
});
test('cashless split and application changes stale mode preview and saved reports remain byte immutable', function () {
    P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '3', 'basis_known' => '1', 'cost_basis' => '27'])]);
    P::mode($this->owner, $this->p);
    $saved = app(NetWorthSnapshot::class)->save($this->owner, 'synthetic-before-split', Currency::QAR, '2026-10-18T12:00:00Z');
    $preview = app(PortfolioService::class)->preview($this->owner, (string) $this->p->id, P::modeInput());
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'split']);
    expect(fn () => app(PortfolioService::class)->confirm($this->owner, (string) $this->p->id, 'synthetic-stale-split', (string) $preview->id, '1'))->toThrow(ValidationException::class);
    expect(DB::table('valuation_reports')->where('id', $saved->id)->value('evidence'))->toBe($saved->evidence);
    expect(app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z')['total'])->toBeNull();
});
test('exact receipts survive archive while changed payload and foreign nested references are refused', function () {
    $input = I::input($this->asset);
    $first = app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'synthetic-archive-retry', $input);
    $this->account->archived_at = now();
    $this->account->save();
    expect(app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'synthetic-archive-retry', $input)->id)->toBe($first->id);
    expect(fn () => app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'synthetic-archive-retry', [...$input, 'amount' => '60']))->toThrow(ValidationException::class);
    $other = User::factory()->create(['is_admin' => true]);
    expect(fn () => app(InvestmentService::class)->reverse($other, (string) $this->p->id, (string) $first->id, 'synthetic-foreign-reverse', 'Synthetic reason'))->toThrow(NotFoundHttpException::class);
});
test('manual instruments and financial contradictions cannot create unit events', function () {
    $manual = P::asset($this->owner, ['kind' => 'manual_investment']);
    foreach ([I::input($manual), I::input($this->asset, ['quantity' => '1.0000000000001']), I::input($this->asset, ['kind' => 'dividend', 'amount' => '1', 'tax' => '2']),
        I::input($this->asset, ['kind' => 'fee', 'quantity' => '1']), I::input($this->asset, ['amount' => 12.5]), I::input($this->asset, ['owner_id' => '1'])] as $input) {
        expect(fn () => app(InvestmentService::class)->record($this->owner, (string) $this->p->id, (string) Str::uuid(), $input))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('investment_events', 0);
});
test('refund cancellation cannot remove cash already consumed by a later retained purchase', function () {
    $p = P::link($this->owner, $a = P::account($this->owner));
    I::fund($this->owner, $a, '63');
    $intent = I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '63']);
    $child = ['subscription_id' => (string) $intent->id];
    I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_pay', 'amount' => '63', ...$child]);
    $refund = I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_refund', 'amount' => '15', ...$child]);
    I::event($this->owner, $p, $this->asset, ['amount' => '15', 'fee' => '0']);
    $before = DB::table('journal_entries')->count();
    expect(fn () => app(InvestmentService::class)->reverse($this->owner, (string) $p->id, (string) $refund->id, 'synthetic-spent-refund-cancel', 'Synthetic dependent cash use'))->toThrow(ValidationException::class);
    expect(I::replay($this->owner, $p)['receivable_minor'])->toBe('4800')->and(I::replay($this->owner, $p)['rows'][0]['cost_basis']['minor'])->toBe('1500');
    $this->assertDatabaseCount('journal_entries', $before);
});
test('cancelling investment receipts cannot remove cash consumed only by a generic ledger expense', function (string $kind) {
    $p = P::link($this->owner, $a = P::account($this->owner));
    $child = [];
    if ($kind === 'ipo_refund') {
        I::fund($this->owner, $a, '17');
        $intent = I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '17']);
        $child = ['subscription_id' => (string) $intent->id];
        I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_pay', 'amount' => '17', ...$child]);
    }
    $input = I::input($this->asset, ['kind' => $kind, 'amount' => '17', ...$child]);
    $dividend = app(InvestmentService::class)->record($this->owner, (string) $p->id, 'synthetic-cash-source', $input);
    app(PostingService::class)->record($this->owner, 'synthetic-spent-dividend-expense', ['kind' => 'expense', 'account_id' => (string) $a->id,
        'amount' => '17', 'effective_date' => '2026-10-18', 'description' => 'Synthetic generic actual consumption']);
    $before = DB::table('journal_entries')->count();
    expect(fn () => app(InvestmentService::class)->reverse($this->owner, (string) $p->id, (string) $dividend->id, 'synthetic-spent-dividend-cancel', 'Synthetic cash source correction'))->toThrow(ValidationException::class);
    expect(fn () => app(InvestmentService::class)->record($this->owner, (string) $p->id, 'synthetic-spent-receipt-correct', [...$input, 'amount' => '16', 'replaces_id' => (string) $dividend->id]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('journal_entries', $before);
})->with(['dividend', 'ipo_refund']);
test('later generic deposits cannot hide a newly negative earlier ledger date after income cancellation', function () {
    $p = P::link($this->owner, $a = P::account($this->owner));
    $dividend = I::event($this->owner, $p, $this->asset, ['kind' => 'dividend', 'amount' => '17', 'effective_date' => '2026-10-16']);
    app(PostingService::class)->record($this->owner, 'synthetic-historical-dividend-expense', ['kind' => 'expense', 'account_id' => (string) $a->id,
        'amount' => '17', 'effective_date' => '2026-10-17', 'description' => 'Synthetic earlier actual consumption']);
    I::fund($this->owner, $a, '17', '2026-10-18');
    expect(fn () => app(InvestmentService::class)->reverse($this->owner, (string) $p->id, (string) $dividend->id, 'synthetic-historical-dividend-cancel', 'Synthetic historical source correction'))->toThrow(ValidationException::class);
    expect(app(PortfolioRead::class)->state($this->owner, (string) $p->id, '2026-10-18T12:00:00Z')['ledger_cash']['minor'])->toBe('1700');
});
test('a backdated release on a previously empty date can reduce a retained external shortfall', function () {
    $p = P::link($this->owner, $a = P::account($this->owner));
    I::fund($this->owner, $a, '75', '2026-10-15');
    $intent = I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '75', 'effective_date' => '2026-10-15']);
    $child = ['subscription_id' => (string) $intent->id];
    I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_reserve', 'amount' => '75', 'effective_date' => '2026-10-15', ...$child]);
    app(PostingService::class)->record($this->owner, 'synthetic-earmark-external-spend', ['kind' => 'expense', 'account_id' => (string) $a->id,
        'amount' => '50', 'effective_date' => '2026-10-16', 'description' => 'Synthetic external cash use']);
    I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_release', 'amount' => '10', 'effective_date' => '2026-10-17', ...$child]);
    expect(I::replay($this->owner, $p)['reserved_minor'])->toBe('6500');
    expect(fn () => I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_reserve', 'amount' => '10', 'effective_date' => '2026-10-17', ...$child]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('investment_event_journals', 0);
});
test('cashless relief correction or cancellation cannot discard its original historical deficit checkpoint', function (string $operation) {
    $p = P::link($this->owner, $a = P::account($this->owner));
    I::fund($this->owner, $a, '75', '2026-10-15');
    $intent = I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '75', 'effective_date' => '2026-10-15']);
    $child = ['subscription_id' => (string) $intent->id];
    I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_reserve', 'amount' => '75', 'effective_date' => '2026-10-15', ...$child]);
    app(PostingService::class)->record($this->owner, 'synthetic-retained-earmark-spend', ['kind' => 'expense', 'account_id' => (string) $a->id,
        'amount' => '50', 'effective_date' => '2026-10-15', 'description' => 'Synthetic external consumption']);
    $input = I::input($this->asset, ['kind' => 'ipo_release', 'amount' => '10', 'effective_date' => '2026-10-16', ...$child]);
    $release = app(InvestmentService::class)->record($this->owner, (string) $p->id, 'synthetic-retained-early-relief', $input);
    I::fund($this->owner, $a, '50', '2026-10-17');
    if ($operation === 'correct') {
        expect(fn () => app(InvestmentService::class)->record($this->owner, (string) $p->id, 'synthetic-moved-relief', [...$input, 'effective_date' => '2026-10-17', 'replaces_id' => (string) $release->id]))->toThrow(ValidationException::class);
    } else {
        expect(fn () => app(InvestmentService::class)->reverse($this->owner, (string) $p->id, (string) $release->id, 'synthetic-cancelled-relief', 'Synthetic retained date check'))->toThrow(ValidationException::class);
    }
    expect(I::replay($this->owner, $p)['reserved_minor'])->toBe('6500');
    $this->assertDatabaseCount('investment_events', 3);
})->with(['correct', 'reverse']);
test('zero and three decimal currencies preserve native fee basis and final disposal residue', function (Currency $currency, string $gross, string $fee, string $sale, string $basis) {
    $p = P::link($this->owner, $a = P::account($this->owner, $currency));
    $asset = P::asset($this->owner, ['currency' => $currency->value]);
    I::fund($this->owner, $a, '2000');
    I::event($this->owner, $p, $asset, ['quantity' => '3', 'amount' => $gross, 'fee' => $fee]);
    foreach (range(1, 3) as $i) {
        I::event($this->owner, $p, $asset, ['kind' => 'sell', 'quantity' => '1', 'amount' => $sale]);
    }
    $r = I::replay($this->owner, $p);
    expect($r['rows'])->toBeEmpty()->and(array_column(array_slice($r['events'], 1), 'allocated_cost_minor'))->toBe(['34', '34', '33']);
    expect(bcadd($r['events'][1]['allocated_cost_minor'], bcadd($r['events'][2]['allocated_cost_minor'], $r['events'][3]['allocated_cost_minor'], 0), 0))->toBe($basis);
})->with([[Currency::JPY, '100', '1', '50', '101'], [Currency::KWD, '0.100', '0.001', '0.050', '101']]);
test('quantity additions remain exact above JavaScript integer range and overflowing holdings or splits roll back', function () {
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '999999999999999999.999999999998', 'amount' => '1', 'fee' => '0']);
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '0.000000000001', 'amount' => '1', 'fee' => '0']);
    expect(I::replay($this->owner, $this->p)['rows'][0]['quantity'])->toBe('999999999999999999.999999999999');
    foreach ([['quantity' => '0.000000000001', 'amount' => '1', 'fee' => '0'], ['kind' => 'split']] as $bad) {
        expect(fn () => I::event($this->owner, $this->p, $this->asset, $bad))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('investment_events', 2);
    $this->assertDatabaseCount('investment_event_journals', 2);
});
