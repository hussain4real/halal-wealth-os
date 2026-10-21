<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DecisionFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->file = tempnam(sys_get_temp_dir(), 'synthetic-private-plan-');
    file_put_contents($this->file, json_encode(F::input(), JSON_THROW_ON_ERROR));
});
afterEach(function () {
    if (is_file($this->file)) {
        unlink($this->file);
    }
});
test('private external plan requires explicit opt in and an existing owner before any records', function () {
    $before = DB::table('users')->count();
    $this->artisan('decisions:import-plan', ['owner' => (string) $this->owner->id, 'path' => $this->file])->assertFailed();
    $this->artisan('decisions:import-plan', ['owner' => '999999', 'path' => $this->file, '--confirm-private' => true])->assertFailed();
    expect(DB::table('users')->count())->toBe($before);
    $this->assertDatabaseCount('decision_opportunities', 0);
});
test('opt-in external synthetic proposal imports idempotently without cash claims trades or extra users', function () {
    $before = DB::table('users')->count();
    for ($i = 0; $i < 2; $i++) {
        $this->artisan('decisions:import-plan', ['owner' => (string) $this->owner->id, 'path' => $this->file, '--confirm-private' => true])->assertSuccessful();
    }
    $this->assertDatabaseCount('decision_opportunities', 1);
    foreach (['postings', 'investment_events', 'goal_reservations', 'decision_reservations'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    expect(DB::table('users')->count())->toBe($before);
});
test('checkout files symlinks malformed private content and oversized content are refused without printing values', function () {
    $link = $this->file.'-symlink';
    symlink(base_path('package.json'), $link);
    try {
        foreach ([base_path('package.json'), $link] as $path) {
            $this->artisan('decisions:import-plan', ['owner' => (string) $this->owner->id, 'path' => $path, '--confirm-private' => true])->assertFailed();
        }
    } finally {
        unlink($link);
    }
    foreach (['{', '[]', str_repeat('x', 64001), json_encode(F::input(['prior_id' => '999']), JSON_THROW_ON_ERROR)] as $bytes) {
        file_put_contents($this->file, $bytes);
        $this->artisan('decisions:import-plan', ['owner' => (string) $this->owner->id, 'path' => $this->file, '--confirm-private' => true])->assertFailed();
    }
    $this->assertDatabaseCount('decision_opportunities', 0);
});
