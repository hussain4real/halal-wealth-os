<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InvestmentFixtures as I;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->account = P::account($this->owner);
    $this->p = P::link($this->owner, $this->account);
    $this->asset = P::asset($this->owner);
    I::fund($this->owner, $this->account);
    $this->actingAs($this->owner);
    $this->url = '/portfolio/'.$this->p->id.'/events';
});
test('activity form receipt exact export and protected cash journal routes resolve', function () {
    $this->get($this->url)->assertInertia(fn (Assert $p) => $p->component('portfolio/Events')->where('book.available.minor', '200000')->has('events.items', 0));
    $this->get($this->url.'/new')->assertInertia(fn (Assert $p) => $p->component('portfolio/EventForm')->has('choices', 1));
    $input = ['idempotency_key' => 'investment-http-purchase', ...I::input($this->asset, ['quantity' => '1.234567890123'])];
    $saved = $this->postJson($this->url, $input)->assertCreated();
    $id = $saved->json('id');
    $this->get($this->url.'/'.$id)->assertInertia(fn (Assert $p) => $p->component('portfolio/Event')->where('event.quantity', '1.234567890123')->where('event.amount.minor', '5600')->where('event.cash_effect.minor', '-5608'));
    $this->get($this->url.'/export')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('event_history.0.quantity', '1.234567890123')->assertJsonPath('event_history.0.amount.minor', '5600');
    $this->get('/portfolio/'.$this->p->id.'/opening')->assertInertia(fn (Assert $p) => $p->has('initial_rows', 0));
});
test('numeric financial JSON unknown fields and spoofed owner are rejected without cash effects', function () {
    foreach (['quantity', 'unit_price', 'amount', 'fee', 'tax', 'split_numerator'] as $field) {
        $this->postJson($this->url, ['idempotency_key' => 'investment-numeric-'.$field, ...I::input($this->asset), $field => 1])->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    foreach (['owner_id' => (string) $this->owner->id, 'shariah_status' => 'verified', 'realized_gain' => '1'] as $field => $value) {
        $this->postJson($this->url, ['idempotency_key' => 'investment-forged-'.$field, ...I::input($this->asset), $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->assertDatabaseCount('investment_events', 0);
    $this->assertDatabaseCount('journal_entries', 1);
});
test('same HTTP request recovers one receipt after response loss and rejects a changed consequential payload', function () {
    $input = ['idempotency_key' => 'investment-http-exact-retry', ...I::input($this->asset)];
    $a = $this->postJson($this->url, $input)->assertCreated();
    $b = $this->postJson($this->url, $input)->assertCreated();
    expect($b->json('id'))->toBe($a->json('id'));
    $this->postJson($this->url, [...$input, 'amount' => '57'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    $this->assertDatabaseCount('investment_events', 1);
    $this->assertDatabaseCount('investment_event_journals', 1);
});
test('another owner including admin cannot list export read select correct reverse or recover foreign facts', function () {
    $event = I::event($this->owner, $this->p, $this->asset);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    foreach ([$this->url, $this->url.'/new', $this->url.'/export', $this->url.'/'.$event->id, $this->url.'/new?replace='.$event->id] as $url) {
        $this->get($url)->assertNotFound();
    }
    $this->postJson($this->url, ['idempotency_key' => 'investment-foreign-write', ...I::input($this->asset)])->assertNotFound();
    $this->postJson($this->url.'/'.$event->id.'/reverse', ['idempotency_key' => 'investment-foreign-reverse', 'reason' => 'Synthetic forbidden action'])->assertNotFound();
});
test('nested foreign event and application cannot be reached through another owned account', function () {
    $event = I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '63']);
    $p = P::link($this->owner, P::account($this->owner));
    $other = '/portfolio/'.$p->id.'/events';
    $this->get($other.'/'.$event->id)->assertNotFound();
    $this->get($other.'/new?replace='.$event->id)->assertNotFound();
    $this->postJson($other, ['idempotency_key' => 'investment-wrong-application', ...I::input($this->asset, ['kind' => 'ipo_pay', 'subscription_id' => (string) $event->id])])->assertNotFound();
});
test('complete financial history survives bounded activity and application pages', function () {
    for ($i = 0; $i < 24; $i++) {
        I::event($this->owner, $this->p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '1']);
    }
    $response = $this->get($this->url)->assertInertia(fn (Assert $p) => $p->has('events.items', 20)->has('book.subscriptions.items', 20)->where('book.subscription_count', 24));
    $export = $this->get($this->url.'/export')->assertOk();
    expect($export->json('event_history'))->toHaveCount(24);
    $ids = array_column($export->json('event_history'), 'id');
    $this->get($this->url.'?after='.$ids[4].'&applications_after='.$ids[4])->assertInertia(fn (Assert $p) => $p->has('events.items', 4)->has('book.subscriptions.items', 4)->where('book.subscription_count', 24));
});
test('correction screen pins retained reference outside latest bounded asset choices', function () {
    $event = I::event($this->owner, $this->p, $this->asset);
    for ($i = 0; $i < 23; $i++) {
        P::asset($this->owner, ['name' => 'Synthetic later reference '.$i]);
    }
    $this->get($this->url.'/new?replace='.$event->id)->assertInertia(fn (Assert $p) => $p->where('prior.id', (string) $event->id)->where('selected_asset', (string) $this->asset->id)
        ->where('choices', fn ($items) => collect($items)->contains(fn ($a) => $a['id'] === (string) $this->asset->id)));
    $this->get($this->url.'/new?q=later%20reference%2022')->assertInertia(fn (Assert $p) => $p->has('choices', 1));
});
test('linked cancellation is one retained receipt and protected generic journal route refuses a partial reversal', function () {
    $event = I::event($this->owner, $this->p, $this->asset);
    $journal = DB::table('investment_event_journals')->where('event_id', $event->id)->value('journal_id');
    $this->postJson('/transactions/'.$journal.'/reverse', ['idempotency_key' => 'investment-http-generic', 'reason' => 'Synthetic forbidden partial'])->assertUnprocessable();
    $input = ['idempotency_key' => 'investment-http-linked-cancel', 'reason' => 'Synthetic complete cancellation'];
    $first = $this->postJson($this->url.'/'.$event->id.'/reverse', $input)->assertCreated();
    expect($this->postJson($this->url.'/'.$event->id.'/reverse', $input)->assertCreated()->json('id'))->toBe($first->json('id'));
    $this->get($this->url.'/'.$event->id)->assertInertia(fn (Assert $p) => $p->where('event.reversed_by', $first->json('id')));
});
test('an opening correction form edits the retained opening instead of replayed trade positions', function () {
    $opening = P::opening($this->owner, $this->p, [P::row($this->asset, ['quantity' => '2'])]);
    I::event($this->owner, $this->p, $this->asset, ['quantity' => '3']);
    $this->get('/portfolio/'.$this->p->id.'/opening')->assertInertia(fn (Assert $p) => $p->where('initial_rows.0.quantity', '2'));
    $this->get('/portfolio/'.$this->p->id)->assertInertia(fn (Assert $p) => $p->where('positions.items.0.quantity', '5'));
    $this->postJson('/portfolio/'.$this->p->id.'/opening', ['idempotency_key' => 'investment-http-unchanged-opening', 'prior_id' => (string) $opening->id,
        'as_of' => '2026-10-18', 'source' => 'Synthetic unchanged opening statement', 'reason' => 'Synthetic explicit unchanged correction', 'rows' => [P::row($this->asset, ['quantity' => '2'])]])->assertCreated();
    $this->get('/portfolio/'.$this->p->id)->assertInertia(fn (Assert $p) => $p->where('positions.items.0.quantity', '5'));
});
test('large account wide paid receivables have explicit unavailable display instead of an export or page exception', function () {
    $p = P::link($this->owner, $account = P::account($this->owner));
    foreach (range(1, 2) as $i) {
        I::fund($this->owner, $account, '92233720368547758.07');
        $intent = I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_intent', 'amount' => '92233720368547758.07']);
        I::event($this->owner, $p, $this->asset, ['kind' => 'ipo_pay', 'amount' => '92233720368547758.07', 'subscription_id' => (string) $intent->id]);
    }
    $this->get('/portfolio/'.$p->id.'/events')->assertOk()->assertInertia(fn (Assert $page) => $page->where('book.receivable', null)->where('book.receivable_minor', '18446744073709551614')->where('book.range_issues.0', 'receivable_out_of_range'));
    $this->get('/portfolio/'.$p->id.'/events/export')->assertOk()->assertJsonPath('investment.receivable_minor', '18446744073709551614');
});
test('investment cash journals expose their owning record and route generic correction to that receipt', function (string $kind) {
    $event = I::event($this->owner, $this->p, $this->asset, ['kind' => $kind]);
    $journal = DB::table('investment_event_journals')->where('event_id', $event->id)->value('journal_id');
    $this->get('/transactions/'.$journal)->assertInertia(fn (Assert $p) => $p->where('transaction.investment.event_id', (string) $event->id)
        ->where('transaction.investment.portfolio_account_id', (string) $this->p->id));
    $this->get('/transactions/'.$journal.'/correct')->assertRedirect($this->url.'/'.$event->id);
})->with(['buy', 'dividend', 'fee']);
