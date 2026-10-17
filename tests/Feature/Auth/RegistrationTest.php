<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('public registration screen is unavailable while the bundled feature remains retained', function () {
    $this->assertFalse(Features::enabled(Features::registration()));
    $this->get('/register')->assertNotFound();
    $this->get('/login')->assertOk();
});

test('public registration submission cannot create or authenticate a user', function () {
    $this->post('/register', ['name' => 'Synthetic Owner', 'email' => 'owner@example.test', 'password' => 'password', 'password_confirmation' => 'password', 'is_admin' => true])->assertNotFound();
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});
