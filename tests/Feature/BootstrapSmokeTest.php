<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
test('test_application_health_and_postgresql_identity', function (): void {
    $this->get('/up')->assertOk();
    $this->assertSame('pgsql', DB::connection()->getDriverName());
    $this->assertSame('halal_wealth_os_test', DB::selectOne('SELECT current_database() AS name')->name);
});
test('test_clock_can_be_frozen_and_restored_deterministically', function (): void {
    $instant = CarbonImmutable::parse('2026-10-17T12:00:00Z');
    $this->travelTo($instant, function () use ($instant): void {
        $this->assertTrue(Date::now()->equalTo($instant));
    });
    $this->assertFalse(CarbonImmutable::hasTestNow());
});
test('test_json_validation_uses_laravel_error_conventions', function (): void {
    Route::post('/__test/validation', function (Request $request): array {
        return $request->validate(['label' => ['required', 'string']]);
    });
    $this->postJson('/__test/validation', [])->assertUnprocessable()->assertJsonValidationErrors(['label']);
});
