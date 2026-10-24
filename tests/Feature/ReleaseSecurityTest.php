<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Domain\Reporting\DashboardSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('password recovery invalidates an existing owner session before financial reads writes or exports', function (string $method, string $path) {
    $owner = User::factory()->create();
    $snapshot = app(DashboardSnapshot::class)->save($owner, 'synthetic-security-retained-snapshot', ['base_currency' => 'QAR', 'settings_id' => '']);
    $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/dashboard');
    $oldSession = session()->all();
    app(ResetUserPassword::class)->reset($owner->fresh(), ['password' => 'Synthetic-recovered-password-47', 'password_confirmation' => 'Synthetic-recovered-password-47']);
    Auth::forgetGuards();
    $path = str_replace('{snapshot}', (string) $snapshot->id, $path);
    $this->withSession($oldSession)->json($method, $path, ['idempotency_key' => 'synthetic-stolen-old-session', 'base_currency' => 'QAR', 'settings_id' => ''])->assertUnauthorized();
    $this->assertDatabaseCount('dashboard_snapshots', 1);
    $this->assertDatabaseCount('postings', 0);
    Auth::forgetGuards();
    $this->post('/login', ['email' => $owner->email, 'password' => 'Synthetic-recovered-password-47'])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();
})->with([['GET', '/copilot'], ['POST', '/copilot/preview'], ['POST', '/copilot/propose'], ['POST', '/copilot/consent'], ['GET', '/dashboard'], ['POST', '/dashboard/snapshots'], ['GET', '/dashboard/snapshots/{snapshot}/export']]);
