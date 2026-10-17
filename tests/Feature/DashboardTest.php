<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
test('test_guests_are_redirected_to_the_login_page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});
test('test_authenticated_users_can_visit_the_dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
