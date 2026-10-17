<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});
test('test_reset_password_link_screen_can_be_rendered', function () {
    $response = $this->get(route('password.request'));
    $response->assertOk();
});
test('test_reset_password_link_can_be_requested', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class);
});
test('test_reset_password_screen_can_be_rendered', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));
        $response->assertOk();

        return true;
    });
});
test('test_password_can_be_reset_with_valid_token', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), ['token' => $notification->token, 'email' => $user->email, 'password' => 'password', 'password_confirmation' => 'password']);
        $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return true;
    });
});
test('test_password_cannot_be_reset_with_invalid_token', function (): void {
    $user = User::factory()->create();
    $response = $this->post(route('password.update'), ['token' => 'invalid-token', 'email' => $user->email, 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123']);
    $response->assertSessionHasErrors('email');
});
