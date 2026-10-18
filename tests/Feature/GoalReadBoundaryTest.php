<?php

use App\Domain\Planning\GoalService;
use App\Domain\Planning\GoalView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures as G;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
test('goal account movement reservation and version history pages are bounded without losing full totals', function () {
    $o = User::factory()->create();
    $a = Fx::account($o);
    G::cash($o, $a, '1000');
    $goals = [];
    for ($i = 0; $i < 24; $i++) {
        $goals[] = G::goal($o, $a, ['opening' => '1']);
    }
    $g = $goals[0];
    $svc = app(GoalService::class);
    for ($i = 0; $i < 24; $i++) {
        G::cash($o, $a, '1');
        $svc->reserve($o, (string) Str::uuid(), (string) $g->id, '1', 'Synthetic history item');
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $v = app(GoalView::class)->index($o, substr(now('UTC')->toDateString(), 0, 7));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($v['goals'])->toHaveCount(20)->and($v['next'])->not->toBeNull();
    $windows = array_filter($queries, fn ($q) => str_contains($q['query'], 'WITH active AS'));
    $balances = array_filter($queries, fn ($q) => str_contains($q['query'], 'opening_minor'));
    expect(count($windows))->toBe(1)->and(count($balances))->toBe(1);
    $second = app(GoalView::class)->index($o, substr(now('UTC')->toDateString(), 0, 7), $v['next']);
    expect($second['goals'])->toHaveCount(4);
    $detail = app(GoalView::class)->show($o, (string) $g->id, substr(now('UTC')->toDateString(), 0, 7));
    expect($detail['movements'])->toHaveCount(20)->and($detail['reservations'])->toHaveCount(20)->and($detail['goal']['nominal']['minor'])->toBe('2500')->and($detail['goal']['backed']['minor'])->toBe('2500');
    $more = app(GoalView::class)->show($o, (string) $g->id, substr(now('UTC')->toDateString(), 0, 7), $detail['movement_next'], $detail['reservation_next']);
    expect($more['movements'])->toHaveCount(5)->and($more['reservations'])->toHaveCount(5);
    $latest = $g;
    for ($i = 0; $i < 22; $i++) {
        $latest = $svc->plan($o, (string) Str::uuid(), G::input($a, ['opening' => '1', 'reason' => 'Synthetic plan revision']), (string) $latest->id);
    }
    $detail = app(GoalView::class)->show($o, (string) $g->id, substr(now('UTC')->toDateString(), 0, 7));
    expect($detail['versions'])->toHaveCount(20)->and($detail['version_next'])->not->toBeNull();
    $old = app(GoalView::class)->show($o, (string) $g->id, substr(now('UTC')->toDateString(), 0, 7), null, null, $detail['version_next']);
    expect($old['versions'])->toHaveCount(3);
});
test('raw root opening snapshots cannot invent dedicated funding and reservation insertion rolls back atomically', function () {
    $o = User::factory()->create();
    $a = Fx::account($o);
    G::cash($o, $a, '100');
    $g = G::goal($o, $a, ['mode' => 'dedicated', 'opening' => '100']);
    $copy = (array) $g;
    unset($copy['id']);
    $copy['reference'] = (string) Str::ulid();
    $copy['family_reference'] = $copy['reference'];
    $copy['opening_minor'] = '99999';
    expect(fn () => DB::transaction(fn () => DB::table('goal_plans')->insert($copy)))->toThrow(PDOException::class);
    $this->assertDatabaseCount('goal_plans', 1);
});
