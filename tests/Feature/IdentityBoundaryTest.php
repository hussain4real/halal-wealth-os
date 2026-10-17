<?php

namespace Tests\Feature;

use App\Filament\Resources\OwnerResource;
use App\Filament\Resources\OwnerResource\Pages\ListOwners;
use App\Filament\Resources\OwnerResource\Pages\ViewOwner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('anonymous requests cannot enter owner or administrative paths', function (string $path) {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/dashboard', '/settings/profile', '/settings/security', '/owners/1', '/admin/owners', '/admin/owners/1']);

test('owner query and route IDs deny reading or changing a second synthetic user', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $this->actingAs($ownerB);

    $this->assertSame([$ownerB->id], User::query()->ownedBy($ownerB)->pluck('id')->all());
    $this->assertFalse(Gate::allows('view', $ownerA));
    $this->assertFalse(Gate::allows('update', $ownerA));
    $this->get(route('owners.show', $ownerA))->assertNotFound();
    $this->patch(route('owners.update', $ownerA), ['name' => 'Changed', 'email' => 'changed@example.test'])->assertNotFound();
    $this->assertSame($ownerA->name, $ownerA->fresh()->name);

    $this->get(route('owners.show', $ownerB))->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/Profile')->where('auth.user.id', $ownerB->id));
    $this->patch(route('owners.update', $ownerB), ['name' => 'Updated owner', 'email' => $ownerB->email])->assertSessionHasNoErrors();
    $this->assertSame('Updated owner', $ownerB->fresh()->name);
});

test('submitted owner IDs and profile capability fields cannot redirect ownership or grant administration', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $this->actingAs($ownerB)->patch(route('profile.update'), [
        'name' => 'Escalated', 'email' => $ownerB->email,
        'id' => $ownerA->id, 'owner_id' => $ownerA->id, 'is_admin' => true,
    ])->assertSessionHasErrors(['id', 'owner_id', 'is_admin']);

    $this->assertSame($ownerA->name, $ownerA->fresh()->name);
    $this->assertSame($ownerB->name, $ownerB->fresh()->name);
    $this->assertFalse($ownerB->fresh()->is_admin);
    $this->assertFalse(Gate::forUser($ownerB)->allows('access-admin'));
    $this->assertFalse($ownerB->isFillable('is_admin'));
    $this->get('/admin/owners')->assertForbidden();
});

test('ordinary users cannot enter direct admin resource URLs or mount their Livewire list', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $this->actingAs($ownerB);
    $this->get('/admin/owners')->assertForbidden();
    $this->get('/admin/owners/'.$ownerA->id)->assertForbidden();
    $this->get('/admin/owners/'.$ownerA->id.'/edit')->assertNotFound();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListOwners::class)->assertForbidden();
});

test('explicit admin capability keeps resource queries and direct or forged record IDs owner scoped', function () {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $ownerB->forceFill(['is_admin' => true])->save();
    $this->actingAs($ownerB);
    $this->assertTrue($ownerB->canAccessPanel(Filament::getPanel('admin')));
    $this->get('/admin/owners')->assertOk()->assertDontSee($ownerA->email);
    $this->get('/admin/owners/'.$ownerB->id)->assertOk();
    $this->get('/admin/owners/'.$ownerA->id)->assertNotFound();
    $this->assertSame([$ownerB->id], OwnerResource::getEloquentQuery()->pluck('id')->all());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ViewOwner::class, ['record' => $ownerA->id])->assertNotFound();
});

test('unverified or revoked admin capability denies the panel on the next request', function () {
    $owner = User::factory()->unverified()->create();
    $owner->forceFill(['is_admin' => true])->save();
    $this->actingAs($owner)->get('/admin/owners')->assertForbidden();
    $owner->forceFill(['email_verified_at' => now()])->save();
    $this->get('/admin/owners')->assertOk();
    $owner->forceFill(['is_admin' => false])->save();
    $this->get('/admin/owners')->assertForbidden();
});

test('personal production workspace and administration require confirmed two factor while enrollment stays accessible', function () {
    $owner = User::factory()->create();
    $owner->forceFill(['is_admin' => true])->save();
    $this->app->instance('env', 'production');
    $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('security.edit'));
    $this->get('/admin/owners')->assertForbidden();
    $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk();
    $owner->forceFill(['two_factor_secret' => encrypt('synthetic-only-secret'), 'two_factor_confirmed_at' => now()])->save();
    $this->get('/dashboard')->assertOk();
    $this->get('/admin/owners')->assertOk();
});
