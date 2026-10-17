<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
test('test_security_page_is_displayed', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    Features::passkeys(['confirmPassword' => true]);
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertInertia(fn (Assert $page) => $page->component('settings/Security')->where('canManagePasskeys', true)->where('passkeys', [])->where('canManageTwoFactor', true)->where('twoFactorEnabled', false));
});
test('test_security_page_requires_password_confirmation_when_enabled', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    $user = User::factory()->create();
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $response = $this->actingAs($user)->get(route('security.edit'));
    $response->assertRedirect(route('password.confirm'));
});
test('test_security_page_renders_without_two_factor_when_feature_is_disabled', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    config(['fortify.features' => []]);
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/Security')->where('canManagePasskeys', false)->where('passkeys', [])->where('canManageTwoFactor', false)->missing('twoFactorEnabled')->missing('requiresConfirmation'));
});
test('test_password_can_be_updated', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password']);
    $response->assertSessionHasNoErrors()->assertRedirect(route('security.edit'));
    $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
});
test('test_correct_password_must_be_provided_to_update_password', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->from(route('security.edit'))->put(route('user-password.update'), ['current_password' => 'wrong-password', 'password' => 'new-password', 'password_confirmation' => 'new-password']);
    $response->assertSessionHasErrors('current_password')->assertRedirect(route('security.edit'));
});
