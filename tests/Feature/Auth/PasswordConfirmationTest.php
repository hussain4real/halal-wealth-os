<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
test('test_confirm_password_screen_can_be_rendered', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('password.confirm'));
    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('auth/ConfirmPassword'));
});
test('test_password_confirmation_requires_authentication', function () {
    $response = $this->get(route('password.confirm'));
    $response->assertRedirect(route('login'));
});
