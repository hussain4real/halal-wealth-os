<?php

use App\Domain\Ledger\PostingService;
use App\Domain\Planning\BudgetService;
use App\Domain\Planning\BudgetView;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\FxFixtures as Fx;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->category = B::category($this->owner);
    $this->rule = B::rule($this->owner, $this->category, ['amount' => '41.00']);
    $this->account = Fx::account($this->owner);
    $this->receipt = fn (int $i, string $date = '2026-10-18', string $description = 'Synthetic bounded receipt') => app(PostingService::class)->record($this->owner, 'read-receipt-'.$i,
        ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '1.00', 'effective_date' => $date, 'description' => $description]);
});

test('monthly activity is bounded while every page retains full classified and unclassified totals', function () {
    $ids = [];
    for ($i = 0; $i < 42; $i++) {
        $journal = ($this->receipt)($i);
        $ids[] = (string) $journal->id;
        if ($i % 2 === 0) {
            app(BudgetService::class)->attribute($this->owner, 'read-classify-'.$i, (string) $journal->id, (string) $this->category->id, 'Synthetic explicit classification');
        }
    }
    ($this->receipt)(100, '2020-01-18');
    $view = app(BudgetView::class);
    $first = $view->month($this->owner, '2026-10');
    expect($first['activity'])->toHaveCount(20)->and($first)->not->toHaveKey('receipts');
    $seen = [];
    $cursor = null;
    do {
        $page = $view->month($this->owner, '2026-10', $cursor);
        expect(count($page['activity']))->toBeLessThanOrEqual(20)
            ->and($page['totals'][0]['actual_expense']['minor'])->toBe('2100')
            ->and($page['totals'][0]['unclassified_expense']['minor'])->toBe('2100')
            ->and($page['comparisons'][0]['variance']['minor'])->toBe('-2000');
        array_push($seen, ...array_column($page['activity'], 'id'));
        $cursor = $page['activity_next_cursor'];
    } while ($cursor !== null);
    expect($seen)->toBe(array_reverse($ids))->and(array_unique($seen))->toHaveCount(42);
    $this->assertDatabaseCount('planning_occurrences', 0);
});

test('eligible receipts paginate all posted dates and search treats wildcard characters literally', function () {
    $ids = [];
    for ($i = 0; $i < 42; $i++) {
        $ids[] = (string) ($this->receipt)($i, $i === 0 ? '2020-01-18' : '2026-10-18', $i === 0 ? 'Synthetic old receipt 50%_ marker' : 'Synthetic bounded receipt')->id;
    }
    $view = app(BudgetView::class);
    $seen = [];
    $cursor = null;
    do {
        $page = $view->receipts($this->owner, (string) $this->rule->id, $cursor, '', null);
        expect(count($page['receipts']))->toBeLessThanOrEqual(20);
        array_push($seen, ...array_column($page['receipts'], 'id'));
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);
    expect($seen)->toBe(array_reverse($ids));
    $this->actingAs($this->owner)->getJson('/budgets/schedules/'.$this->rule->id.'/receipts?'.http_build_query(['q' => '%_']))
        ->assertOk()->assertJsonCount(1, 'receipts')->assertJsonPath('receipts.0.id', $ids[0])->assertJsonPath('receipts.0.date', '2020-01-18');
    $this->assertDatabaseCount('planning_attributions', 0);
});

test('activity cursor retains both currency rows of one balanced expense across a page boundary', function () {
    $posting = app(PostingService::class);
    $usd = Fx::account($this->owner, Currency::USD);
    $qarExpense = $posting->systemAccountFor($this->owner, Currency::QAR, 'expense');
    $usdExpense = $posting->systemAccountFor($this->owner, Currency::USD, 'expense');
    // App posting ports require one currency. The retained database contract is
    // balanced per currency; exercise that legal shape without disabling guards.
    $multi = DB::transaction(function () use ($usd, $qarExpense, $usdExpense) {
        $id = DB::table('journal_entries')->insertGetId(['owner_id' => $this->owner->id, 'reference' => (string) Str::ulid(),
            'idempotency_key' => 'read-multi-currency', 'payload_hash' => str_repeat('0', 64), 'request_payload' => '{}',
            'kind' => 'expense', 'status' => 'pending', 'effective_date' => '2026-10-18', 'description' => 'Synthetic two native currencies']);
        foreach ([[$this->account, '-100'], [$qarExpense, '100'], [$usd, '-200'], [$usdExpense, '200']] as [$account, $minor]) {
            DB::table('postings')->insert(['journal_id' => $id, 'owner_id' => $this->owner->id, 'account_id' => $account->id, 'currency_code' => $account->currency_code->value, 'amount_minor' => $minor]);
        }
        DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return (object) ['id' => $id];
    });
    for ($i = 0; $i < 19; $i++) {
        ($this->receipt)($i);
    }
    $view = app(BudgetView::class);
    $first = $view->month($this->owner, '2026-10');
    $second = $view->month($this->owner, '2026-10', $first['activity_next_cursor']);
    expect($first['activity'])->toHaveCount(20)->and($first['activity_next_cursor'])->toBe($multi->id.':QAR')
        ->and($second['activity'])->toHaveCount(1)->and($second['activity'][0]['id'])->toBe((string) $multi->id)
        ->and($second['activity'][0]['value']['currency'])->toBe('USD')->and($second['activity_next_cursor'])->toBeNull();
    // A multi-currency service receipt is activity, but is not an allocatable single-currency receipt.
    $eligible = $view->receipts($this->owner, (string) $this->rule->id, null, '', null);
    expect(array_column($eligible['receipts'], 'id'))->not->toContain((string) $multi->id);
});

test('bounded previews and full history retain all allocation totals including a hidden reversal and release', function () {
    $service = app(BudgetService::class);
    $matches = [];
    $journals = [];
    for ($i = 0; $i < 41; $i++) {
        $journals[] = $journal = ($this->receipt)($i);
        $matches[] = $service->allocate($this->owner, 'read-match-'.$i, (string) $this->rule->id, '2026-10-18', (string) $journal->id, '1.00');
    }
    $view = app(BudgetView::class);
    $occurrence = $view->month($this->owner, '2026-10')['occurrences'][0];
    expect($occurrence['allocations'])->toHaveCount(10)->and($occurrence['allocation_count'])->toBe('41')
        ->and($occurrence['matched']['minor'])->toBe('4100')->and($occurrence['remaining']['minor'])->toBe('0')->and($occurrence['status'])->toBe('matched');
    app(PostingService::class)->reverse($this->owner, (string) $journals[0]->id, 'read-reverse-oldest', 'Synthetic hidden reversed receipt');
    $service->release($this->owner, 'read-release-oldest', (string) $matches[1]->id, 'Synthetic hidden released link');
    $seen = [];
    $cursor = null;
    do {
        $page = $view->history($this->owner, $occurrence['id'], $cursor);
        expect(count($page['occurrence']['allocations']))->toBeLessThanOrEqual(20)
            ->and($page['occurrence']['matched']['minor'])->toBe('3900')->and($page['occurrence']['remaining']['minor'])->toBe('200')
            ->and($page['occurrence']['needs_review'])->toBeTrue()->and($page['occurrence']['allocation_count'])->toBe('41');
        array_push($seen, ...$page['occurrence']['allocations']);
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);
    expect(array_column($seen, 'id'))->toBe(array_reverse(array_map(fn ($a) => (string) $a->id, $matches)))
        ->and($seen[39]['released'])->toBeTrue()->and($seen[40]['effective'])->toBeFalse();
    $monthly = $view->month($this->owner, '2026-10')['occurrences'][0];
    expect($monthly['matched']['minor'])->toBe('3900')->and($monthly['needs_review'])->toBeTrue();
    $this->actingAs($this->owner)->get('/budgets/occurrences/'.$occurrence['id'])
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('budgets/History')->has('history.occurrence.allocations', 20)->where('history.occurrence.matched.minor', '3900'));
});

test('remembered full or reversed receipt remains available for a lost committed allocation response', function () {
    $journal = ($this->receipt)(1, '2020-01-18');
    $service = app(BudgetService::class);
    $match = $service->allocate($this->owner, 'read-uncertain-match', (string) $this->rule->id, '2026-10-18', (string) $journal->id, '1.00');
    $url = '/budgets/schedules/'.$this->rule->id.'/receipts?selected='.$journal->id;
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonCount(0, 'receipts')
        ->assertJsonPath('selected.id', (string) $journal->id)->assertJsonPath('selected.remaining.minor', '0');
    expect($service->allocate($this->owner, 'read-uncertain-match', (string) $this->rule->id, '2026-10-18', (string) $journal->id, '1.00')->id)->toBe($match->id);
    app(PostingService::class)->reverse($this->owner, (string) $journal->id, 'read-uncertain-reversed', 'Synthetic reversed while retrying');
    $this->getJson($url)->assertOk()->assertJsonPath('selected.status', 'reversed')->assertJsonCount(0, 'receipts');
    expect($service->allocate($this->owner, 'read-uncertain-match', (string) $this->rule->id, '2026-10-18', (string) $journal->id, '1.00')->id)->toBe($match->id);
    $this->assertDatabaseCount('planning_allocations', 1);
});

test('receipt and history reads hide foreign identities even for administrators', function (bool $admin) {
    $journal = ($this->receipt)(1);
    $match = app(BudgetService::class)->allocate($this->owner, 'read-owned-match', (string) $this->rule->id, '2026-10-18', (string) $journal->id, '1.00');
    $occurrence = DB::table('planning_allocations')->where('id', $match->id)->value('occurrence_id');
    $other = User::factory()->create(['is_admin' => $admin]);
    $ownRule = B::rule($other, B::category($other));
    $this->actingAs($other)->getJson('/budgets/schedules/'.$this->rule->id.'/receipts')->assertNotFound();
    $this->get('/budgets/occurrences/'.$occurrence)->assertNotFound();
    $this->getJson('/budgets/schedules/'.$ownRule->id.'/receipts?selected='.$journal->id)->assertNotFound();
})->with([false, true]);

test('new read boundaries reject malformed cursors and enforce the existing authentication gates', function () {
    $url = '/budgets/schedules/'.$this->rule->id.'/receipts';
    $this->getJson($url)->assertUnauthorized();
    $this->get('/budgets/occurrences/1')->assertRedirect('/login');
    $this->actingAs($this->owner);
    foreach (['before[]=1', 'before=0001', 'before=9223372036854775808', 'selected[]=1'] as $query) {
        $this->getJson($url.'?'.$query)->assertNotFound();
    }
    foreach (['activity_before[]=1', 'activity_before=1', 'activity_before=1:XYZ'] as $query) {
        $this->get('/budgets?'.$query)->assertNotFound();
    }
    $this->getJson($url.'?q[]=x')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson($url.'?q='.str_repeat('x', 121))->assertUnprocessable();
    $this->get('/budgets/occurrences/1?before[]=1')->assertNotFound();
    $this->app->instance('env', 'production');
    $this->getJson($url)->assertRedirect(route('security.edit'));
    $this->get('/budgets/occurrences/1')->assertRedirect(route('security.edit'));
});
