<?php

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\JournalPosted;
use App\Domain\Ledger\PostingService;
use App\Domain\Planning\GoalBacking;
use App\Domain\Portfolio\InvestmentService;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

require_once __DIR__.'/../Support/InvestmentRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->owner = User::factory()->create();
    $this->account = P::account($this->owner);
    $this->p = P::link($this->owner, $this->account);
    $this->asset = P::asset($this->owner);
    I::fund($this->owner, $this->account);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
test('two PostgreSQL backends recover one durable purchase for the same request', function () {
    $cmd = ['operation' => 'record', 'portfolio' => (string) $this->p->id, 'key' => 'investment-race-same-buy', 'input' => I::input($this->asset)];
    $results = concurrentInvestmentRace($this->owner->id, [$cmd, $cmd]);
    expect(array_column($results, 'status'))->toBe(['success', 'success'])->and($results[0]['id'])->toBe($results[1]['id']);
    $this->assertDatabaseCount('investment_events', 1);
    $this->assertDatabaseCount('investment_event_journals', 1);
    expect(I::replay($this->owner, $this->p)['rows'][0]['quantity'])->toBe('8');
});
test('different concurrent sale identities cannot jointly oversell the same holding', function () {
    I::event($this->owner, $this->p, $this->asset);
    $input = I::input($this->asset, ['kind' => 'sell', 'quantity' => '6', 'amount' => '60']);
    $cmd = ['operation' => 'record', 'portfolio' => (string) $this->p->id, 'key' => 'investment-race-sale-one', 'input' => $input];
    $results = concurrentInvestmentRace($this->owner->id, [$cmd, [...$cmd, 'key' => 'investment-race-sale-two', 'input' => [...$input, 'action_reference' => 'SYNTHETIC-SALE-TWO']]]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation'])->and(I::replay($this->owner, $this->p)['rows'][0]['quantity'])->toBe('2');
    $this->assertDatabaseCount('investment_events', 2);
});
test('different request identities cannot duplicate the same corporate action', function () {
    I::event($this->owner, $this->p, $this->asset);
    $cmd = ['operation' => 'record', 'portfolio' => (string) $this->p->id, 'key' => 'investment-race-split-one', 'input' => I::input($this->asset, ['kind' => 'split'])];
    $results = concurrentInvestmentRace($this->owner->id, [$cmd, [...$cmd, 'key' => 'investment-race-split-two']]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation'])->and(I::replay($this->owner, $this->p)['rows'][0]['quantity'])->toBe('16');
    $this->assertDatabaseCount('investment_event_journals', 1);
});
test('concurrent allotment retry returns one confirmed quantity without another payment', function () {
    $intent = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '63']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_pay', 'amount' => '63', 'subscription_id' => (string) $intent->id]);
    $cmd = ['operation' => 'record', 'portfolio' => (string) $this->p->id, 'key' => 'investment-race-allot', 'input' => I::input($this->asset, ['kind' => 'ipo_allot', 'quantity' => '12', 'amount' => '48', 'subscription_id' => (string) $intent->id])];
    $results = concurrentInvestmentRace($this->owner->id, [$cmd, $cmd]);
    expect(array_column($results, 'status'))->toBe(['success', 'success'])->and($results[0]['id'])->toBe($results[1]['id']);
    expect(I::replay($this->owner, $this->p)['receivable_minor'])->toBe('1500')->and(I::replay($this->owner, $this->p)['rows'][0]['quantity'])->toBe('12');
    $this->assertDatabaseCount('investment_event_journals', 1);
});
test('concurrent refunds cannot overclear one paid receivable', function () {
    $intent = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '63']);
    I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_pay', 'amount' => '63', 'subscription_id' => (string) $intent->id]);
    $input = I::input($this->asset, ['kind' => 'ipo_refund', 'amount' => '40', 'subscription_id' => (string) $intent->id]);
    $cmd = ['operation' => 'record', 'portfolio' => (string) $this->p->id, 'key' => 'investment-refund-race-one', 'input' => $input];
    $results = concurrentInvestmentRace($this->owner->id, [$cmd, [...$cmd, 'key' => 'investment-refund-race-two', 'input' => [...$input, 'action_reference' => 'SYNTHETIC-REFUND-TWO']]]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation'])->and(I::replay($this->owner, $this->p)['receivable_minor'])->toBe('2300');
});
test('concurrent distinct applications cannot earmark the same investment cash twice', function () {
    $intents = array_map(fn () => I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '1500']), range(1, 2));
    $commands = array_map(fn ($intent) => ['operation' => 'record', 'portfolio' => (string) $this->p->id, 'key' => 'investment-shared-earmark-'.$intent->id,
        'input' => I::input($this->asset, ['kind' => 'ipo_reserve', 'amount' => '1500', 'subscription_id' => (string) $intent->id])], $intents);
    $statuses = array_column(concurrentInvestmentRace($this->owner->id, $commands), 'status');
    sort($statuses);
    expect($statuses)->toBe(['success', 'validation'])->and(I::replay($this->owner, $this->p)['reserved_minor'])->toBe('150000');
    $this->assertDatabaseCount('investment_events', 3);
    $this->assertDatabaseCount('investment_event_journals', 0);
});
test('cash-link failure at actual commit retains neither event cash nor retry identity', function () {
    DB::unprepared("CREATE FUNCTION synthetic_fail_investment_link() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic cash-link failure'; END $$; CREATE TRIGGER synthetic_fail_investment_link BEFORE INSERT ON investment_event_journals FOR EACH ROW EXECUTE FUNCTION synthetic_fail_investment_link()");
    try {
        expect(fn () => I::event($this->owner, $this->p, $this->asset, [], 'investment-failed-cash-link'))->toThrow(PDOException::class, 'Synthetic cash-link failure');
    } finally {
        DB::unprepared('DROP TRIGGER synthetic_fail_investment_link ON investment_event_journals; DROP FUNCTION synthetic_fail_investment_link();');
    }
    $this->assertDatabaseCount('investment_events', 0);
    $this->assertDatabaseCount('investment_event_journals', 0);
    $this->assertDatabaseCount('journal_entries', 1);
    $this->assertDatabaseCount('postings', 2);
});
test('deferred PostgreSQL guard rejects a raw orphan event at outer commit', function () {
    $original = I::event($this->owner, $this->p, $this->asset);
    $row = (array) $original;
    unset($row['id']);
    $row['reference'] = (string) Str::ulid();
    $row['idempotency_key'] = 'investment-raw-orphan';
    $row['action_reference'] = 'SYNTHETIC-ORPHAN';
    expect(fn () => DB::transaction(fn () => DB::table('investment_events')->insert($row)))->toThrow(PDOException::class, 'must commit together');
    $this->assertDatabaseCount('investment_events', 1);
});
test('database rejects raw standalone reversal of a linked investment cash journal', function () {
    $event = I::event($this->owner, $this->p, $this->asset);
    $original = DB::table('journal_entries')->where('id', DB::table('investment_event_journals')->where('event_id', $event->id)->value('journal_id'))->first();
    expect(fn () => DB::transaction(function () use ($original) {
        $copy = (array) $original;
        unset($copy['id']);
        $copy = [...$copy, 'reference' => (string) Str::ulid(), 'idempotency_key' => 'investment-raw-member-reversal', 'kind' => 'reversal', 'status' => 'pending', 'posted_at' => null, 'reverses_journal_id' => $original->id];
        $id = DB::table('journal_entries')->insertGetId($copy);
        foreach (DB::table('postings')->where('journal_id', $original->id)->get() as $p) {
            $leg = (array) $p;
            unset($leg['id']);
            DB::table('postings')->insert([...$leg, 'journal_id' => $id, 'amount_minor' => bcsub('0', (string) $p->amount_minor, 0)]);
        }
        DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
        DB::table('journal_entries')->where('id', $original->id)->update(['status' => 'reversed']);
    }))->toThrow(PDOException::class, 'reverse with its retained investment event');
    expect(DB::table('journal_entries')->where('id', $original->id)->value('status'))->toBe('posted');
    $this->assertDatabaseCount('investment_events', 1);
});
test('exact investment cash guard cannot reuse a personal transfer as a purchase control leg', function () {
    $event = I::event($this->owner, $this->p, $this->asset);
    $destination = P::account($this->owner);
    $transfer = app(PostingService::class)->record($this->owner, 'investment-mismatched-transfer', ['kind' => 'transfer', 'account_id' => (string) $this->account->id,
        'destination_account_id' => (string) $destination->id, 'amount' => '56.08', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic separate personal transfer']);
    expect(fn () => DB::transaction(function () use ($event, $transfer) {
        $row = (array) $event;
        unset($row['id']);
        $row['reference'] = (string) Str::ulid();
        $row['idempotency_key'] = 'investment-forged-transfer-link';
        $row['action_reference'] = 'SYNTHETIC-FORGED-CONTROL';
        $id = DB::table('investment_events')->insertGetId($row);
        DB::table('investment_event_journals')->insert(['event_id' => $id, 'owner_id' => $this->owner->id, 'portfolio_account_id' => $this->p->id, 'currency' => 'QAR', 'journal_id' => $transfer->id]);
    }))->toThrow(PDOException::class, 'control legs');
});
test('raw reversal must reverse its actual cash member rather than link an ordinary adjustment', function () {
    $event = I::event($this->owner, $this->p, $this->asset);
    expect(fn () => DB::transaction(function () use ($event) {
        $row = (array) $event;
        unset($row['id']);
        $row = [...$row, 'reference' => (string) Str::ulid(), 'idempotency_key' => 'investment-forged-adjustment-reversal', 'kind' => 'reversal', 'reverses_id' => $event->id, 'amount_minor' => '0', 'fee_minor' => '0', 'tax_minor' => '0'];
        $id = DB::table('investment_events')->insertGetId($row);
        $posting = app(PostingService::class);
        $offset = $posting->systemAccountFor($this->owner, Currency::QAR, 'clearing');
        $journal = $posting->postBalanced($this->owner, 'investment-forged-adjustment-member', [['account_id' => (string) $this->account->id, 'minor' => '5608'], ['account_id' => (string) $offset->id, 'minor' => '-5608']], 'Synthetic ordinary adjustment');
        DB::table('investment_event_journals')->insert(['event_id' => $id, 'owner_id' => $this->owner->id, 'portfolio_account_id' => $this->p->id, 'currency' => 'QAR', 'journal_id' => $journal->id]);
    }))->toThrow(PDOException::class, 'actual original cash member');
});
test('correct request context cannot disguise a personal counterparty as investment clearing', function () {
    $event = I::event($this->owner, $this->p, $this->asset);
    $destination = P::account($this->owner);
    expect(fn () => DB::transaction(function () use ($event, $destination) {
        $row = (array) $event;
        unset($row['id']);
        $row = [...$row, 'reference' => (string) Str::ulid(), 'idempotency_key' => 'investment-forged-personal-context', 'action_reference' => 'SYNTHETIC-FORGED-PERSONAL-CONTROL'];
        $id = DB::table('investment_events')->insertGetId($row);
        $journal = app(PostingService::class)->postBalanced($this->owner, 'investment-forged-personal-cash', [
            ['account_id' => (string) $this->account->id, 'minor' => '-5608'], ['account_id' => (string) $destination->id, 'minor' => '5608'],
        ], 'Synthetic invalid personal counterparty', $event->effective_date, ['investment_event_id' => (string) $id]);
        DB::table('investment_event_journals')->insert(['event_id' => $id, 'owner_id' => $this->owner->id, 'portfolio_account_id' => $this->p->id, 'currency' => 'QAR', 'journal_id' => $journal->id]);
    }))->toThrow(PDOException::class, 'control legs');
    $this->assertDatabaseCount('investment_events', 1);
});
test('failed after-commit notification recovers the committed receipt without reposting', function () {
    $input = I::input($this->asset);
    $listeners = Event::getRawListeners()[JournalPosted::class] ?? [];
    Event::listen(JournalPosted::class, fn () => throw new RuntimeException('Synthetic lost delivery'));
    try {
        expect(fn () => app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'investment-aftercommit-retry', $input))->toThrow(RuntimeException::class, 'Synthetic lost delivery');
    } finally {
        Event::forget(JournalPosted::class);
        foreach ($listeners as $listener) {
            Event::listen(JournalPosted::class, $listener);
        }
    }
    $event = app(InvestmentService::class)->record($this->owner, (string) $this->p->id, 'investment-aftercommit-retry', $input);
    expect($event->id)->toBe(DB::table('investment_events')->sole()->id)->and(I::replay($this->owner, $this->p)['rows'][0]['quantity'])->toBe('8');
    $this->assertDatabaseCount('investment_events', 1);
    $this->assertDatabaseCount('journal_entries', 2);
});
test('investment reservation pool cannot overlap bank cash goal backing', function () {
    $bank = app(CreateAccount::class)->create($this->owner, 'Synthetic bank for explicit transfer', AccountType::Bank, Currency::QAR, null);
    expect(fn () => P::link($this->owner, $bank))->toThrow(ValidationException::class);
    expect(fn () => app(GoalBacking::class)->account($this->owner, (string) $this->account->id))->toThrow(ValidationException::class);
});
test('empty event schema roundtrip preserves existing opening journal bytes and retained events refuse downgrade', function () {
    P::opening($this->owner, $this->p, [P::row($this->asset)]);
    $journals = DB::table('journal_entries')->orderBy('id')->get()->toJson();
    $opening = DB::table('portfolio_opening_rows')->get()->toJson();
    $decisions = require database_path('migrations/2026_10_21_064500_create_decision_records.php');
    $decisions->down();
    $purification = require database_path('migrations/2026_10_20_073000_create_purification_records.php');
    $purification->down();
    $migration = require database_path('migrations/2026_10_18_171000_create_investment_event_subledger.php');
    $migration->down();
    expect(Schema::hasTable('investment_events'))->toBeFalse();
    $migration->up();
    $purification->up();
    $decisions->up();
    expect(DB::table('journal_entries')->orderBy('id')->get()->toJson())->toBe($journals)->and(DB::table('portfolio_opening_rows')->get()->toJson())->toBe($opening);
    $event = I::event($this->owner, $this->p, $this->asset);
    expect(fn () => DB::transaction(fn () => DB::table('investment_events')->where('id', $event->id)->update(['reason' => 'Mutated'])))->toThrow(PDOException::class);
    expect(fn () => DB::transaction(fn () => DB::table('investment_event_journals')->where('event_id', $event->id)->delete()))->toThrow(PDOException::class);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'before DDL');
    expect(Schema::hasTable('investment_events'))->toBeTrue();
});
