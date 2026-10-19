<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssessmentFixtures as A;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->asset = P::asset($this->owner);
    $this->base = '/assets/'.$this->asset->id.'/assessments';
    $this->actingAs($this->owner);
});
test('owner records attributed evidence and reads immutable detail history and private export', function () {
    $this->get($this->base)->assertInertia(fn (Assert $p) => $p->component('shariah/Index')->where('summary.classification', 'pending_review'));
    $this->get($this->base.'/new')->assertInertia(fn (Assert $p) => $p->component('shariah/Form')->where('asset.id', (string) $this->asset->id)->where('stale', false));
    $response = $this->postJson($this->base, ['idempotency_key' => 'synthetic-assessment-http', ...A::input($this->owner, $this->asset)])->assertCreated();
    $id = $response->json('id');
    $this->get($response->json('url'))->assertInertia(fn (Assert $p) => $p->component('shariah/Record')->where('assessment.id', $id)->where('assessment.actor_id', (string) $this->owner->id));
    $this->get($this->base)->assertInertia(fn (Assert $p) => $p->where('summary.classification', 'compliant')->has('current', 1));
    $this->get($this->base.'/export')->assertOk()->assertHeader('Content-Type', 'application/json')->assertJsonCount(1, 'history')->assertJsonPath('history.0.actor_id', (string) $this->owner->id);
    $this->get('/assets/'.$this->asset->id)->assertInertia(fn (Assert $p) => $p->where('assessment_summary.classification', 'compliant'));
});
test('all foreign owner and administrator reads writes history and export are isolated', function (bool $admin) {
    $record = A::record($this->owner, $this->asset);
    $this->actingAs(User::factory()->create(['is_admin' => $admin]));
    foreach ([$this->base, $this->base.'/new', $this->base.'/'.$record->id, $this->base.'/'.$record->id.'/revise', $this->base.'/export'] as $url) {
        $this->getJson($url)->assertNotFound();
    }
    $this->postJson($this->base, ['idempotency_key' => 'synthetic-foreign-assessment', ...A::input($this->owner, $this->asset)])->assertNotFound();
    $this->assertDatabaseCount('shariah_assessments', 1);
})->with([false, true]);
test('admin can use only the same own evidence workflow with trusted role attribution', function () {
    $this->owner->forceFill(['is_admin' => true])->save();
    $this->postJson($this->base, ['idempotency_key' => 'synthetic-admin-own-evidence', ...A::input($this->owner, $this->asset)])->assertCreated();
    $this->get($this->base.'/export')->assertJsonPath('history.0.actor_is_admin', true);
    $this->get('/admin')->assertRedirect('/admin/owners');
    $this->get('/admin/owners')->assertOk()->assertSee('Shariah evidence');
});
test('forged authority identity timestamps nested references and invalid cutoffs are rejected', function (array $change, string $field) {
    $this->postJson($this->base, ['idempotency_key' => 'synthetic-forged-assessment', ...A::input($this->owner, $this->asset), ...$change])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('shariah_assessments', 0);
})->with([
    [['owner_id' => '8'], 'owner_id'], [['actor_id' => '8'], 'actor_id'], [['actor_is_admin' => true], 'actor_is_admin'],
    [['recorded_at' => '2020-01-01T00:00:00Z'], 'recorded_at'], [['verified' => true], 'verified'],
    [['evidence_refs' => [['url' => 'https://example.com']]], 'evidence_refs.0'], [['evidence_refs' => []], 'classification'],
    [['source_url' => 'data:text/html,bad'], 'source_url'],
]);
test('unknown malformed as-of cursor and cross-asset detail requests fail closed', function () {
    $record = A::record($this->owner, $this->asset);
    $other = P::asset($this->owner);
    $this->getJson($this->base.'?cutoff[]=2026-10-19')->assertUnprocessable();
    $this->getJson($this->base.'?cutoff=2030-01-01T00:00:00Z')->assertUnprocessable();
    $this->getJson($this->base.'?after[]=1')->assertNotFound();
    $this->getJson('/assets/'.$other->id.'/assessments/'.$record->id)->assertNotFound();
    $this->getJson('/assets/'.$other->id.'/assessments/'.$record->id.'/revise')->assertNotFound();
});
test('complete source state and export do not become incomplete when history is paged', function () {
    for ($i = 0; $i < 23; $i++) {
        A::record($this->owner, $this->asset, ['provider' => 'Synthetic source '.$i, 'classification' => $i === 0 ? 'non_compliant' : 'compliant']);
    }
    $this->get($this->base)->assertInertia(fn (Assert $p) => $p->has('history.items', 20)->has('current', 23)->where('summary.conflict', true)->where('summary.source_count', 23)->where('history.next', fn ($v): bool => is_string($v)));
    $this->get($this->base.'/export')->assertJsonCount(23, 'history')->assertJsonCount(23, 'current')->assertJsonPath('summary.conflict', true);
});
test('revision form detects stale prior while a committed same-key submission still recovers', function () {
    $first = A::record($this->owner, $this->asset);
    $input = ['idempotency_key' => 'synthetic-http-revision', ...A::input($this->owner, $this->asset, ['supersedes_id' => (string) $first->id])];
    $second = $this->postJson($this->base, $input)->assertCreated()->json('id');
    $this->get($this->base.'/'.$first->id.'/revise')->assertInertia(fn (Assert $p) => $p->where('stale', true));
    $this->postJson($this->base, $input)->assertCreated()->assertJsonPath('id', $second);
    $this->postJson($this->base, [...$input, 'idempotency_key' => 'synthetic-other-http-revision'])->assertUnprocessable()->assertJsonValidationErrors('supersedes_id');
    $this->get($this->base.'/'.$first->id)->assertInertia(fn (Assert $p) => $p->where('latest_id', $second));
});
test('guest unverified and production-without-two-factor cannot enter evidence routes', function () {
    auth()->logout();
    $this->get($this->base)->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create());
    $this->get($this->base)->assertRedirect('/email/verify');
    $this->app->detectEnvironment(fn () => 'production');
    $this->actingAs($this->owner);
    $this->get($this->base)->assertRedirect('/settings/security');
});
