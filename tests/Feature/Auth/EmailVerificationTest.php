<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});
test('test_email_verification_screen_can_be_rendered', function () {
    $user = User::factory()->unverified()->create();
    $response = $this->actingAs($user)->get(route('verification.notice'));
    $response->assertOk();
});
test('test_unverified_users_are_redirected_to_the_email_verification_prompt', function (): void {
    $user = User::factory()->unverified()->create();
    $response = $this->actingAs($user)->get(route('appearance.edit'));
    $response->assertRedirect(route('verification.notice'));
});
test('test_email_can_be_verified', function () {
    $user = User::factory()->unverified()->create();
    Event::fake();
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $response = $this->actingAs($user)->get($verificationUrl);
    Event::assertDispatched(Verified::class);
    $this->assertTrue($user->fresh()->hasVerifiedEmail());
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});
test('test_email_is_not_verified_with_invalid_hash', function () {
    $user = User::factory()->unverified()->create();
    Event::fake();
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1('wrong-email')]);
    $this->actingAs($user)->get($verificationUrl);
    Event::assertNotDispatched(Verified::class);
    $this->assertFalse($user->fresh()->hasVerifiedEmail());
});
test('test_email_is_not_verified_with_invalid_user_id', function (): void {
    $user = User::factory()->unverified()->create();
    Event::fake();
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => 123, 'hash' => sha1($user->email)]);
    $this->actingAs($user)->get($verificationUrl);
    Event::assertNotDispatched(Verified::class);
    $this->assertFalse($user->fresh()->hasVerifiedEmail());
});
test('test_verified_user_is_redirected_to_dashboard_from_verification_prompt', function (): void {
    $user = User::factory()->create();
    Event::fake();
    $response = $this->actingAs($user)->get(route('verification.notice'));
    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect(route('dashboard', absolute: false));
});
test('test_already_verified_user_visiting_verification_link_is_redirected_without_firing_event_again', function (): void {
    $user = User::factory()->create();
    Event::fake();
    $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs($user)->get($verificationUrl)->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    Event::assertNotDispatched(Verified::class);
    $this->assertTrue($user->fresh()->hasVerifiedEmail());
});
