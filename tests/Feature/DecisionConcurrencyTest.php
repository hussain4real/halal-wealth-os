<?php

use App\Domain\Decisions\DecisionRead;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DecisionFixtures as F;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

require_once __DIR__.'/../Support/DecisionRace.php';
uses(TestCase::class);
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    $this->owner = User::factory()->create();
    $this->account = Fx::account($this->owner, Currency::NGN);
    G::cash($this->owner, $this->account, '1000');
    $this->goal = G::goal($this->owner, $this->account, ['opening' => '0']);
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});
function decisionStatuses(array $results): array
{
    $s = array_column($results, 'status');
    sort($s);

    return $s;
}

test('duplicate plan requests on distinct blocked PostgreSQL backends recover one immutable receipt', function () {
    $c = ['op' => 'plan', 'key' => 'synthetic-race-plan', 'input' => F::input()];
    $r = concurrentDecisionRace($this->owner->id, [$c, $c]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('decision_opportunities', 1);
});
test('competing thesis revisions retain one successor with no branching history', function () {
    $p = F::plan($this->owner);
    $c = ['op' => 'plan', 'input' => F::input(['prior_id' => (string) $p->id])];
    $r = concurrentDecisionRace($this->owner->id, [[...$c, 'key' => 'synthetic-race-revision-a'], [...$c, 'key' => 'synthetic-race-revision-b']]);
    expect(decisionStatuses($r))->toBe(['success', 'validation']);
    $this->assertDatabaseCount('decision_opportunities', 2);
});
test('competing tranche reservations across different goals cannot exceed plan capacity', function () {
    $p = F::plan($this->owner);
    $b = G::goal($this->owner, $this->account, ['opening' => '0']);
    $c = ['op' => 'reserve', 'input' => ['opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'amount' => '400', 'reason' => 'Synthetic competing capacity']];
    $r = concurrentDecisionRace($this->owner->id, [[...$c, 'key' => 'synthetic-race-reserve-a', 'input' => [...$c['input'], 'goal_id' => (string) $this->goal->id]], [...$c, 'key' => 'synthetic-race-reserve-b', 'input' => [...$c['input'], 'goal_id' => (string) $b->id]]]);
    expect(decisionStatuses($r))->toBe(['success', 'validation'])->and(app(DecisionRead::class)->totals($this->owner, $p->series_id, 'initial')['reserved'])->toBe('40000');
});
test('duplicate reservation requests atomically recover one goal claim and one decision association', function () {
    $p = F::plan($this->owner);
    $c = ['op' => 'reserve', 'key' => 'synthetic-race-reserve-retry', 'input' => ['opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'goal_id' => (string) $this->goal->id, 'amount' => '500', 'reason' => 'Synthetic exact claim']];
    $r = concurrentDecisionRace($this->owner->id, [$c, $c]);
    expect(array_column($r, 'status'))->toBe(['success', 'success'])->and($r[0]['id'])->toBe($r[1]['id']);
    $this->assertDatabaseCount('decision_reservations', 1);
    $this->assertDatabaseCount('goal_reservations', 1);
});
test('revision and reservation serialize so a retired tranche never receives stale new protection', function () {
    $p = F::plan($this->owner);
    $r = concurrentDecisionRace($this->owner->id, [
        ['op' => 'plan', 'key' => 'synthetic-race-shrink', 'input' => F::input(['prior_id' => (string) $p->id, 'target' => '300', 'tranches' => '[{"key":"reserve","label":"Later","amount":"300","condition":"Review"}]'])],
        ['op' => 'reserve', 'key' => 'synthetic-race-old-tranche', 'input' => ['opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'goal_id' => (string) $this->goal->id, 'amount' => '500', 'reason' => 'Synthetic racing reserve']],
    ]);
    expect(decisionStatuses($r))->toBe(['success', 'validation']);
});
test('cash withdrawal racing reservation cannot present an unbacked claim as funded', function () {
    $p = F::plan($this->owner);
    $r = concurrentDecisionRace($this->owner->id, [
        ['op' => 'withdraw', 'key' => 'synthetic-race-withdrawal', 'input' => ['kind' => 'expense', 'account_id' => (string) $this->account->id, 'amount' => '800', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic later expense']],
        ['op' => 'reserve', 'key' => 'synthetic-race-withdraw-reserve', 'input' => ['opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'goal_id' => (string) $this->goal->id, 'amount' => '500', 'reason' => 'Synthetic cash contention']],
    ]);
    expect($r[0]['status'])->toBe('success')->and($r[1]['status'])->toBeIn(['success', 'validation']);
    if ($r[1]['status'] === 'success') {
        expect(app(DecisionRead::class)->detail($this->owner, (string) $p->id)['reservations'][0]['backing'])->toMatchArray(['amount' => '200.00', 'shortfall' => '300.00']);
    }
});
test('actual link racing source reversal retains attribution at most once with zero current spend', function () {
    $asset = P::asset($this->owner, ['currency' => 'NGN']);
    $account = P::account($this->owner, Currency::NGN);
    $portfolio = P::link($this->owner, $account);
    I::fund($this->owner, $account);
    $event = I::event($this->owner, $portfolio, $asset);
    $p = F::plan($this->owner, ['asset_id' => (string) $asset->id]);
    $r = concurrentDecisionRace($this->owner->id, [
        ['op' => 'link', 'key' => 'synthetic-race-link', 'input' => ['opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'event_id' => (string) $event->id, 'reason' => 'Synthetic link contention']],
        ['op' => 'reverse', 'key' => 'synthetic-race-reverse', 'portfolio_id' => (string) $portfolio->id, 'event_id' => (string) $event->id],
    ]);
    expect($r[1]['status'])->toBe('success')->and($r[0]['status'])->toBeIn(['success', 'validation'])->and(app(DecisionRead::class)->totals($this->owner, $p->series_id, 'initial')['spent'])->toBe('0');
});
test('committed decision rows reject mutation forged owners roles series totals and reused reservations', function () {
    $p = F::plan($this->owner);
    $r = F::reserve($this->owner, $p, $this->goal);
    foreach (['decision_opportunities' => $p, 'decision_reservations' => $r] as $table => $row) {
        foreach (['update', 'delete'] as $op) {
            expect(fn () => DB::transaction(fn () => $op === 'delete' ? DB::table($table)->where('id', $row->id)->delete() : DB::table($table)->where('id', $row->id)->update(['reason' => 'Forbidden overwrite'])))->toThrow(PDOException::class);
        }
    }
    $other = User::factory()->create();
    foreach ([['actor_is_admin' => true], ['owner_id' => $other->id, 'actor_id' => $other->id], ['series_id' => (string) Str::ulid()], ['tranche_key' => 'missing']] as $change) {
        $raw = (array) $r;
        unset($raw['id']);
        $raw = [...$raw, 'reference' => (string) Str::ulid(), 'idempotency_key' => (string) Str::uuid(), ...$change];
        expect(fn () => DB::transaction(fn () => DB::table('decision_reservations')->insert($raw)))->toThrow(PDOException::class);
    }
    foreach ([['target_minor' => '99999'], ['actor_is_admin' => true], ['currency' => 'XYZ'], ['tranches' => '[]']] as $change) {
        $raw = (array) $p;
        unset($raw['id']);
        $ref = (string) Str::ulid();
        $raw = [...$raw, 'reference' => $ref, 'series_id' => $ref, 'idempotency_key' => (string) Str::uuid(), ...$change];
        expect(fn () => DB::transaction(fn () => DB::table('decision_opportunities')->insert($raw)))->toThrow(PDOException::class);
    }
});
test('empty decision schema roundtrip preserves earlier finances and retained history refuses downgrade before DDL', function () {
    $m = require database_path('migrations/2026_10_21_064500_create_decision_records.php');
    $before = DB::table('postings')->orderBy('id')->get()->toJson();
    $m->down();
    expect(Schema::hasTable('decision_opportunities'))->toBeFalse();
    $m->up();
    expect(DB::table('postings')->orderBy('id')->get()->toJson())->toBe($before);
    F::plan($this->owner);
    expect(fn () => $m->down())->toThrow(RuntimeException::class, 'before DDL');
    expect(Schema::hasTable('decision_event_links'))->toBeTrue();
});

test('competing actual links on distinct backends attribute one event globally once and retain immutable history', function () {
    $asset = P::asset($this->owner, ['currency' => 'NGN']);
    $account = P::account($this->owner, Currency::NGN);
    $portfolio = P::link($this->owner, $account);
    I::fund($this->owner, $account);
    $event = I::event($this->owner, $portfolio, $asset);
    $p = F::plan($this->owner, ['asset_id' => (string) $asset->id]);
    $b = F::plan($this->owner, ['asset_id' => (string) $asset->id]);
    $r = concurrentDecisionRace($this->owner->id, [
        ['op' => 'link', 'key' => 'synthetic-race-unique-event-a', 'input' => ['opportunity_id' => (string) $p->id, 'tranche_key' => 'initial', 'event_id' => (string) $event->id, 'reason' => 'Synthetic global attribution']],
        ['op' => 'link', 'key' => 'synthetic-race-unique-event-b', 'input' => ['opportunity_id' => (string) $b->id, 'tranche_key' => 'initial', 'event_id' => (string) $event->id, 'reason' => 'Synthetic global attribution']],
    ]);
    expect(decisionStatuses($r))->toBe(['success', 'validation']);
    $this->assertDatabaseCount('decision_event_links', 1);
    $link = DB::table('decision_event_links')->first();
    foreach (['update', 'delete'] as $op) {
        expect(fn () => DB::transaction(fn () => $op === 'delete' ? DB::table('decision_event_links')->where('id', $link->id)->delete() : DB::table('decision_event_links')->where('id', $link->id)->update(['reason' => 'Forbidden overwrite'])))->toThrow(PDOException::class);
    }
    $raw = (array) $link;
    unset($raw['id']);
    $raw = [...$raw, 'reference' => (string) Str::ulid(), 'idempotency_key' => (string) Str::uuid()];
    expect(fn () => DB::transaction(fn () => DB::table('decision_event_links')->insert($raw)))->toThrow(PDOException::class);
});
