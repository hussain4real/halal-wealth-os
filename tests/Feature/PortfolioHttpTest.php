<?php

use App\Domain\Portfolio\PortfolioService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->account = P::account($this->owner);
    $this->portfolio = P::link($this->owner, $this->account);
    $this->asset = P::asset($this->owner);
    $this->url = '/portfolio/'.$this->portfolio->id;
    $this->actingAs($this->owner);
});
function portfolioOpeningPayload(object $asset, array $changes = []): array
{
    return ['idempotency_key' => 'http-opening-identity', 'prior_id' => '', 'as_of' => '2026-10-18', 'source' => 'Synthetic HTTP statement', 'reason' => 'Synthetic opening', 'rows' => [P::row($asset)], ...$changes];
}
test('all portfolio screens resolve and unconfirmed account mode is explicit', function () {
    foreach (['/assets', '/assets/new', '/assets/'.$this->asset->id, '/assets/'.$this->asset->id.'/revise', '/portfolio', $this->url, $this->url.'/opening', $this->url.'/mode'] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get('/accounts/'.$this->account->id)->assertInertia(fn (Assert $p) => $p->where('account.valuation_mode', 'unconfirmed')->where('account.portfolio_id', (string) $this->portfolio->id)->where('account.net_worth_contribution', null));
    $this->get('/accounts')->assertInertia(fn (Assert $p) => $p->where('accounts.0.valuation_mode', 'unconfirmed'));
});
test('fractional display and private export preserve all exact strings and unknown basis', function () {
    $input = portfolioOpeningPayload($this->asset, ['rows' => [P::row($this->asset, ['quantity' => '9007199254740993.123456789012', 'unit_price' => '0.000000000001'])]]);
    $this->postJson($this->url.'/opening', $input)->assertCreated();
    $this->get($this->url)->assertInertia(fn (Assert $p) => $p->where('positions.items.0.quantity', '9007199254740993.123456789012')->where('positions.items.0.unit_price', '0.000000000001')->where('positions.items.0.cost_basis', null));
    $response = $this->get($this->url.'/export')->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('account.rows.0.quantity'))->toBe('9007199254740993.123456789012')->and($response->json('account.rows.0.basis_known'))->toBeFalse()->and($response->json('account.rows.0.cost_basis'))->toBeNull()->and($response->json('account.ledger_evidence'))->toBeNull();
});
test('JSON monetary numerics and forged ownership compliance and row fields are rejected', function () {
    foreach (['quantity', 'unit_price', 'cost_basis'] as $field) {
        $row = P::row($this->asset, [$field => 1]);
        $this->postJson($this->url.'/opening', portfolioOpeningPayload($this->asset, ['rows' => [$row]]))->assertUnprocessable()->assertJsonValidationErrors('rows.0.'.$field);
    }
    foreach (['owner_id' => (string) $this->owner->id, 'shariah_status' => 'verified'] as $field => $value) {
        $this->postJson($this->url.'/opening', portfolioOpeningPayload($this->asset, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->postJson($this->url.'/opening', portfolioOpeningPayload($this->asset, ['rows' => [P::row($this->asset, ['profit' => '100'])]]))->assertUnprocessable();
    $this->assertDatabaseCount('portfolio_openings', 0);
});
test('mode workflow exposes immutable preview and commits only after acknowledgement', function () {
    $input = ['idempotency_key' => 'http-preview-identity', ...P::modeInput(['mode' => 'aggregate', 'aggregate_value' => '50', 'aggregate_as_of' => '2026-10-18'])];
    $preview = $this->postJson($this->url.'/previews', $input)->assertCreated()->json('id');
    $this->assertDatabaseCount('portfolio_modes', 0);
    $this->get($this->url.'/previews/'.$preview)->assertInertia(fn (Assert $p) => $p->where('preview.evidence.before.value', null)->where('preview.evidence.difference_minor', null)->where('preview.evidence.after.value.minor', '5000')->where('preview.stale', false));
    $payload = ['idempotency_key' => 'http-confirm-identity', 'preview_id' => $preview, 'acknowledge' => '0'];
    $this->postJson($this->url.'/confirm', $payload)->assertUnprocessable();
    $payload['acknowledge'] = '1';
    $first = $this->postJson($this->url.'/confirm', $payload)->assertCreated();
    $this->travel(11)->minutes();
    expect($this->postJson($this->url.'/confirm', $payload)->assertCreated()->json('id'))->toBe($first->json('id'));
    $this->assertDatabaseCount('portfolio_modes', 1);
    $this->assertDatabaseCount('journal_entries', 0);
});
test('administrator cannot enumerate export revise or write another owners investment evidence', function () {
    $preview = app(PortfolioService::class)->preview($this->owner, (string) $this->portfolio->id, P::modeInput());
    $other = User::factory()->create(['is_admin' => true]);
    $this->actingAs($other);
    foreach ([$this->url, $this->url.'/opening', $this->url.'/mode', $this->url.'/export', $this->url.'/previews/'.$preview->id, '/assets/'.$this->asset->id, '/assets/'.$this->asset->id.'/revise'] as $url) {
        $this->get($url)->assertNotFound();
    }
    $this->postJson($this->url.'/opening', portfolioOpeningPayload($this->asset))->assertNotFound();
    $this->postJson($this->url.'/previews', ['idempotency_key' => 'foreign-preview-key', ...P::modeInput()])->assertNotFound();
    $this->postJson($this->url.'/confirm', ['idempotency_key' => 'foreign-confirm-key', 'preview_id' => (string) $preview->id, 'acknowledge' => '1'])->assertNotFound();
    $this->postJson('/portfolio', ['idempotency_key' => 'foreign-link-key', 'account_id' => (string) $this->account->id, 'platform_label' => 'Synthetic label', 'source' => 'Synthetic source'])->assertNotFound();
    $this->get('/assets')->assertInertia(fn (Assert $p) => $p->has('choices.items', 0));
    $this->get('/portfolio')->assertInertia(fn (Assert $p) => $p->has('accounts.items', 0));
});
test('a preview cannot be confirmed through another owned account', function () {
    $second = P::link($this->owner, P::account($this->owner));
    $preview = app(PortfolioService::class)->preview($this->owner, (string) $this->portfolio->id, P::modeInput());
    $this->get('/portfolio/'.$second->id.'/previews/'.$preview->id)->assertNotFound();
    $this->postJson('/portfolio/'.$second->id.'/confirm', ['idempotency_key' => 'wrong-parent-confirm', 'preview_id' => (string) $preview->id, 'acknowledge' => '1'])->assertNotFound();
});
test('opening reply loss retry preserves one retained event and rejects changed key payload', function () {
    $input = portfolioOpeningPayload($this->asset);
    $first = $this->postJson($this->url.'/opening', $input)->assertCreated();
    $again = $this->postJson($this->url.'/opening', $input)->assertCreated();
    expect($first->json('id'))->toBe($again->json('id'));
    $this->postJson($this->url.'/opening', [...$input, 'source' => 'Changed source'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->assertDatabaseCount('portfolio_openings', 1);
    $this->assertDatabaseCount('portfolio_opening_rows', 1);
});
test('deep positions disclose twenty but totals export and stale fingerprints include all rows', function () {
    $rows = [];
    for ($i = 0; $i < 23; $i++) {
        $rows[] = P::row(P::asset($this->owner, ['name' => 'Synthetic position '.$i]), ['quantity' => '1', 'unit_price' => '2']);
    }
    P::opening($this->owner, $this->portfolio, $rows);
    P::mode($this->owner, $this->portfolio);
    $this->get($this->url)->assertInertia(fn (Assert $p) => $p->has('positions.items', 20)->where('account.position_count', 23)->where('account.contribution.value.minor', '4600')->where('positions.next', fn ($value) => is_string($value)));
    $export = $this->get($this->url.'/export')->assertOk();
    expect($export->json('account.rows'))->toHaveCount(23);
    $firstRow = $export->json('account.rows.3.id');
    $this->get($this->url.'?positions_after='.$firstRow)->assertInertia(fn (Assert $p) => $p->has('positions.items', 3)->where('account.contribution.value.minor', '4600'));
});
test('guest and unverified owners cannot view or export investments', function () {
    auth()->logout();
    $this->get($this->url.'/export')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get('/assets')->assertRedirect('/email/verify');
});
