<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
});
test('test_registration_screen_can_be_rendered', function () {
    $response = $this->get(route('register'));
    $response->assertOk();
});
test('test_new_users_can_register', function () {
    $response = $this->post(route('register.store'), ['name' => 'Test User', 'email' => 'test@example.com', 'password' => 'password', 'password_confirmation' => 'password']);
    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});
