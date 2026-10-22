<?php

use App\Models\User;
use App\Support\Release\ReleaseReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('readiness checks real database migrations and returns only a minimal status', function () {
    $this->get('/up')->assertOk();
    $this->getJson('/ready')->assertExactJson(['status' => 'ready'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    DB::table('migrations')->where('id', DB::table('migrations')->max('id'))->delete();
    $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
});

test('private release checker rejects unsafe configuration without revealing values', function () {
    config(['app.debug' => true, 'app.url' => 'http://synthetic-internal.invalid', 'app.key' => 'synthetic-invalid-key']);
    $report = app(ReleaseReadiness::class)->inspect();
    expect($report['result'])->toBe('FAIL')->and($report['checks']['debug_off'])->toBeFalse()
        ->and($report['checks']['https_application_url'])->toBeFalse()->and($report['checks']['valid_application_key'])->toBeFalse()
        ->and($report['deployment_authorized'])->toBeFalse()
        ->and(json_encode($report))->not->toContain('synthetic-invalid-key', 'synthetic-internal.invalid');
    $this->artisan('release:check')->assertFailed();
});

test('private release checker passes an explicitly isolated production-like profile while retaining deployment signoff', function () {
    $this->app['env'] = 'production';
    try {
        config(['app.debug' => false, 'app.url' => 'https://private-v1.example.test', 'session.secure' => true,
            'session.http_only' => true, 'session.encrypt' => true, 'session.same_site' => 'lax', 'session.driver' => 'file', 'mail.default' => 'smtp']);
        $report = app(ReleaseReadiness::class)->inspect();
        expect($report['result'])->toBe('PASS')->and($report['deployment_authorized'])->toBeFalse();
        $this->artisan('release:check')->assertSuccessful();
    } finally {
        $this->app['env'] = 'testing';
    }
});

test('debug-off production error responses disclose no exception trace or secret marker', function () {
    $this->app['env'] = 'production';
    config(['app.debug' => false]);
    Route::get('/synthetic-release-failure', fn () => throw new RuntimeException('SYNTHETIC-PRIVATE-ERROR-MARKER'));
    try {
        $this->get('/synthetic-release-failure')->assertStatus(500)->assertDontSee('SYNTHETIC-PRIVATE-ERROR-MARKER')->assertDontSee('Stack trace');
        $this->post('/register', ['name' => 'Synthetic blocked owner', 'email' => 'blocked@example.test', 'password' => 'password'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    } finally {
        $this->app['env'] = 'testing';
    }
});

test('confirmed two-factor enrollment login and single-use recovery protect production data routes', function () {
    $owner = User::factory()->create();
    $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/dashboard');
    $this->app['env'] = 'production';
    try {
        $this->get('/dashboard')->assertRedirectToRoute('security.edit');
        $this->get('/settings/data/export')->assertRedirectToRoute('security.edit');
        $this->postJson('/user/confirm-password', ['password' => 'password', '_token' => session()->token()])->assertSuccessful();
        $this->get('/settings/security')->assertOk();
        $this->postJson('/user/two-factor-authentication', ['_token' => session()->token()])->assertSuccessful();
        $owner->refresh();
        expect($owner->two_factor_secret)->not->toBeNull()->and($owner->two_factor_confirmed_at)->toBeNull();
        $this->get('/dashboard')->assertRedirectToRoute('security.edit');
        $otp = app(Google2FA::class)->getCurrentOtp(decrypt($owner->two_factor_secret));
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $otp, '_token' => session()->token()])->assertSuccessful();
        $owner->refresh();
        expect($owner->hasEnabledTwoFactorAuthentication())->toBeTrue();
        $recovery = $owner->recoveryCodes()[0];
        $this->get('/dashboard')->assertOk();
        $this->get('/admin')->assertForbidden();
        $this->post('/logout', ['_token' => session()->token()])->assertRedirect('/');
        Auth::forgetGuards();
        $this->post('/login', ['email' => $owner->email, 'password' => 'password', '_token' => session()->token()])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['recovery_code' => $recovery, '_token' => session()->token()])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($owner);
        expect($owner->fresh()->recoveryCodes())->not->toContain($recovery);
        $this->post('/logout', ['_token' => session()->token()])->assertRedirect('/');
        Auth::forgetGuards();
        $this->post('/login', ['email' => $owner->email, 'password' => 'password', '_token' => session()->token()])->assertRedirect('/two-factor-challenge');
        $this->postJson('/two-factor-challenge', ['recovery_code' => $recovery, '_token' => session()->token()])->assertUnprocessable();
        $this->assertGuest();
    } finally {
        $this->app['env'] = 'testing';
    }
});
