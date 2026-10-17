<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});
test('test_two_factor_challenge_redirects_to_login_when_not_authenticated', function (): void {
    $response = $this->get(route('two-factor.login'));
    $response->assertRedirect(route('login'));
});
test('test_two_factor_challenge_can_be_rendered', function (): void {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $user = User::factory()->withTwoFactor()->create();
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    $this->get(route('two-factor.login'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorChallenge'));
});
