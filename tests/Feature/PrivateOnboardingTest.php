<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('console onboarding validates and creates only the initial synthetic owner with explicit capability', function () {
    Notification::fake();
    $this->artisan('owner:onboard')
        ->expectsQuestion('Owner name', 'Synthetic Owner')
        ->expectsQuestion('Owner email', 'OWNER@EXAMPLE.TEST')
        ->expectsQuestion('Owner password', 'synthetic-test-password')
        ->expectsQuestion('Confirm owner password', 'synthetic-test-password')
        ->expectsConfirmation('Create the initial private owner with the explicit admin capability?', 'yes')
        ->assertSuccessful();

    $owner = User::query()->sole();
    $this->assertSame('owner@example.test', $owner->email);
    $this->assertTrue($owner->is_admin);
    $this->assertNull($owner->email_verified_at);
    $this->assertNull($owner->two_factor_confirmed_at);
});

test('initial onboarding cannot add another account after any user exists', function () {
    User::factory()->create();
    $this->artisan('owner:onboard')->expectsOutput('Interactive initial onboarding requires an empty user store.')->assertFailed();
    $this->assertDatabaseCount('users', 1);
});

test('noninteractive onboarding never provisions an owner', function () {
    $this->artisan('owner:onboard', ['--no-interaction' => true])->expectsOutput('Interactive initial onboarding requires an empty user store.')->assertFailed();
    $this->assertDatabaseCount('users', 0);
});
