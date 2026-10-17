<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
test('test_profile_page_is_displayed', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('profile.edit'));
    $response->assertOk();
});
test('test_profile_information_can_be_updated', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'test@example.com']);
    $response->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
    $user->refresh();
    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});
test('test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Test User', 'email' => $user->email]);
    $response->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
    $this->assertNotNull($user->refresh()->email_verified_at);
});
test('test_user_can_delete_their_account', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);
    $response->assertSessionHasNoErrors()->assertRedirect(route('home'));
    $this->assertGuest();
    $this->assertNull($user->fresh());
});
test('test_correct_password_must_be_provided_to_delete_account', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'wrong-password']);
    $response->assertSessionHasErrors('password')->assertRedirect(route('profile.edit'));
    $this->assertNotNull($user->fresh());
});

test('profile email update normalizes before saving and subsequent login', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => 'Updated.Owner@EXAMPLE.TEST',
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    $this->assertSame('updated.owner@example.test', $user->refresh()->email);
    $this->assertNull($user->email_verified_at);
    $this->post(route('logout'));
    $this->post(route('login.store'), [
        'email' => 'UPDATED.OWNER@example.test',
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('profile email validation rejects another accounts case variant', function () {
    User::factory()->create(['email' => 'taken@example.test']);
    $user = User::factory()->create();
    $originalEmail = $user->email;

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => 'TAKEN@EXAMPLE.TEST',
    ])->assertSessionHasErrors('email');
    $this->assertSame($originalEmail, $user->refresh()->email);
});

test('case only profile email edit keeps verification', function () {
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $verifiedAt = $user->email_verified_at;

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => 'OWNER@EXAMPLE.TEST',
    ])->assertSessionHasNoErrors();
    $this->assertSame('owner@example.test', $user->refresh()->email);
    $this->assertTrue($verifiedAt->equalTo($user->email_verified_at));
});
