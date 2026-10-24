<?php

use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

test('oauth guest callback does not save its code in an intended login URL', function () {
    $this->get('/drive/callback?state='.str_repeat('a', 64).'&code=synthetic-one-use-code')
        ->assertUnauthorized()->assertSessionMissing('url.intended')->assertHeader('Cache-Control', 'no-store, private');
    expect(json_encode(session()->all()))->not->toContain('synthetic-one-use-code');
});

test('oauth unverified callback fails without flashing code or retaining an intended verification URL', function () {
    $this->actingAs(User::factory()->unverified()->make());
    $this->get('/drive/callback?state='.str_repeat('a', 64).'&code=synthetic-one-use-code')
        ->assertForbidden()->assertSessionMissing('url.intended')->assertHeader('Cache-Control', 'no-store, private');
    expect(json_encode(session()->all()))->not->toContain('synthetic-one-use-code');
});

test('oauth callback preserves the production two factor requirement without a credential redirect', function () {
    $this->actingAs(User::factory()->make());
    $this->app->instance('env', 'production');
    $this->get('/drive/callback?state='.str_repeat('a', 64).'&code=synthetic-one-use-code')
        ->assertForbidden()->assertSessionMissing('url.intended');
    expect(json_encode(session()->all()))->not->toContain('synthetic-one-use-code');
});

test('throttled html oauth callback cannot retain credentials before its controller', function () {
    for ($i = 0; $i < 21; $i++) {
        $response = $this->get('/drive/callback?state='.str_repeat('a', 64).'&code=synthetic-throttled-code');
    }
    $response->assertStatus(429);
    expect(json_encode(session()->all()))->not->toContain('synthetic-throttled-code');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
});

test('stale authenticated session oauth callback cannot redirect or retain credentials', function () {
    $this->actingAs(User::factory()->make())->withSession(['password_hash_web' => 'synthetic-stale-password-hash']);
    $response = $this->get('/drive/callback?state='.str_repeat('a', 64).'&code=synthetic-stale-session-code');
    $response->assertUnauthorized();
    expect(json_encode(session()->all()))->not->toContain('synthetic-stale-session-code');
    $response->assertSessionMissing('url.intended')->assertHeader('Referrer-Policy', 'no-referrer');
});
