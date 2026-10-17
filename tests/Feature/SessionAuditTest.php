<?php

namespace Tests\Feature;

use App\Domain\Identity\SecurityAudit;
use App\Domain\Identity\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('login rotates the session and logout invalidates it and rotates the csrf token', function () {
    $owner = User::factory()->create();
    $this->get(route('login'));
    $oldId = session()->getId();
    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($owner);
    $this->assertNotSame($oldId, session()->getId());
    $token = session()->token();
    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
    $this->assertNotSame($token, session()->token());
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('password recovery endpoints limit requests independently of submitted account identity', function () {
    Notification::fake();
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('password.email'), ['email' => 'synthetic'.$i.'@example.test'])->assertStatus(302);
    }
    $this->post(route('password.email'), ['email' => 'another@example.test'])->assertTooManyRequests();
    $this->post(route('password.update'), ['email' => 'another@example.test', 'token' => 'fake'])->assertTooManyRequests();
});

test('security audit records actor attribution while rejecting sensitive and nested arbitrary context', function () {
    $logger = \Mockery::mock(LoggerInterface::class);
    Log::shouldReceive('channel')->once()->with('security')->andReturn($logger);
    $logger->shouldReceive('notice')->once()->with('identity.profile_updated', ['actor_id' => 11, 'subject_id' => 12]);
    app(SecurityAudit::class)->record(SecurityEvent::ProfileUpdated, 11, [
        'subject_id' => 12, 'email' => 'synthetic@example.test', 'password' => 'synthetic-secret',
        'token' => 'synthetic-token', 'context' => ['recovery_codes' => 'secret'],
        'session_id' => 'synthetic-session', 'amount' => '999.99',
    ]);
});

test('real authentication events emit attributed structured records without request credentials', function () {
    $owner = User::factory()->create();
    $logger = \Mockery::mock(LoggerInterface::class);
    Log::shouldReceive('channel')->with('security')->andReturn($logger);
    $logger->shouldReceive('notice')->once()->with('auth.login_failed', ['actor_id' => null, 'subject_id' => $owner->id]);
    $logger->shouldReceive('notice')->once()->with('auth.login', ['actor_id' => $owner->id, 'subject_id' => null]);
    $logger->shouldReceive('notice')->once()->with('auth.logout', ['actor_id' => $owner->id, 'subject_id' => null]);
    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'wrong-secret']);
    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'password']);
    $this->post(route('logout'));
});

test('forgotten password reset records the unauthenticated requester separately from its target', function () {
    Notification::fake();
    $owner = User::factory()->create();
    $logger = \Mockery::mock(LoggerInterface::class);
    Log::shouldReceive('channel')->with('security')->andReturn($logger);
    $records = [];
    $logger->shouldReceive('notice')->andReturnUsing(function (string $message, array $context) use (&$records): void {
        $records[] = ['message' => $message, 'context' => $context];
    });
    $this->post(route('password.email'), ['email' => $owner->email])->assertSessionHasNoErrors();

    Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use ($owner): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token, 'email' => $owner->email,
            'password' => 'synthetic-new-password', 'password_confirmation' => 'synthetic-new-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(Hash::check('synthetic-new-password', $owner->fresh()->password));

        return true;
    });
    $this->assertSame([['message' => 'auth.password_reset', 'context' => ['actor_id' => null, 'subject_id' => $owner->id]]], $records);
});
