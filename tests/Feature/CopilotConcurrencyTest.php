<?php

use App\Domain\Operations\Channel;
use App\Domain\Operations\Operations;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CopilotFixtures;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../Support/CopilotRace.php';
beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});
afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('independent postgres contenders cannot overspend the final request reservation', function () {
    $owner = User::factory()->create();
    CopilotFixtures::allow($owner);
    config(['copilot.requests_per_day' => 1]);
    $results = concurrentCopilotRace($owner->id, [['key' => (string) Str::uuid()], ['key' => (string) Str::uuid()]]);
    expect(array_column($results, 'status'))->toContain('ok', 429);
    expect(array_values(array_filter(array_column($results, 'outcome'))))->toBe(['complete']);
    expect(DB::table('copilot_requests')->count())->toBe(1)->and((int) DB::table('copilot_requests')->sum('reserved_tokens'))->toBe(16384);
});

test('concurrent identical request keys retain a single durable charged claim', function () {
    $owner = User::factory()->create();
    CopilotFixtures::allow($owner);
    $key = (string) Str::uuid();
    $results = concurrentCopilotRace($owner->id, [['key' => $key], ['key' => $key]]);
    expect(array_column($results, 'status'))->toBe(['ok', 'ok']);
    expect(array_column($results, 'outcome'))->toContain('complete', 'already_charged');
    expect(DB::table('copilot_requests')->count())->toBe(1);
});

test('operational ceiling arbitrates independent contenders before the larger native allowance', function () {
    $owner = User::factory()->create();
    CopilotFixtures::allow($owner);
    $operations = app(Operations::class);
    $policy = $operations->policy($owner, Channel::Copilot);
    $generation = $policy['generation'];
    unset($policy['generation']);
    $operations->configure($owner, Channel::Copilot, $generation, [...$policy, 'requests_daily' => 1]);
    $results = concurrentCopilotRace($owner->id, [['key' => (string) Str::uuid()], ['key' => (string) Str::uuid()]]);
    expect(array_column($results, 'status'))->toContain('ok', 429);
    expect(DB::table('operation_reservations')->count())->toBe(1)->and(DB::table('copilot_requests')->count())->toBe(1);
});
