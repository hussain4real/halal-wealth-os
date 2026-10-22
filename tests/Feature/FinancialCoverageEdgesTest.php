<?php

use App\Domain\Ledger\FxObservationService;
use App\Domain\Ledger\UnavailableAccountBalances;
use App\Domain\Portfolio\PositionPricing;
use App\Domain\Reporting\DashboardRules;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\DashboardFixtures as D;
use Tests\Support\PortfolioFixtures as P;
use Tests\Support\PurificationFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('unavailable balance adapter preserves unknown rather than returning fabricated zero', function () {
    $owner = User::factory()->create();
    $account = P::account($owner);
    $balance = (new UnavailableAccountBalances)->forAccount($account);
    expect($balance->money)->toBeNull()->and($balance->unavailableReason)->not->toBeNull();
});

test('FX observation boundary rejects forged ownership invalid direction and invalid exact rates without retaining observations', function (array $changes) {
    $owner = User::factory()->create();
    $input = ['base_currency' => 'QAR', 'quote_currency' => 'USD', 'value' => '1.25', 'source' => 'Synthetic source', 'observed_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'effective_at' => now('UTC')->format('Y-m-d\TH:i:s\Z')];
    try {
        app(FxObservationService::class)->record($owner, 'synthetic-quality-fx', [...$input, ...$changes]);
        $this->fail('Invalid observation must be rejected.');
    } catch (ValidationException) {
        $this->assertDatabaseCount('fx_observations', 0);
    }
})->with([[['owner_id' => '999']], [['quote_currency' => 'QAR']], [['value' => '-1']]]);

test('oversized marked value remains explicitly partial instead of wrapping or fabricating a value', function () {
    $owner = User::factory()->create();
    $account = P::account($owner);
    $row = ['kind' => 'equity', 'asset_family' => 'synthetic-overflow-family', 'currency' => 'QAR', 'quantity' => '2', 'unit_price' => '92233720368547758.07', 'cost_basis' => null];
    $date = now('UTC')->toDateString();
    $result = app(PositionPricing::class)->value($owner, (string) $account->id, $row, ['id' => '1', 'as_of' => $date, 'source' => 'Synthetic oversized statement'], now('UTC')->format('Y-m-d\TH:i:s\Z'));
    expect($result['market_value'])->toBeNull()->and($result['reason'])->toBe('market_value_out_of_range');
});

test('custom purification base requires a retained explanation before any obligation writes', function () {
    $owner = User::factory()->create();
    $fixture = F::distribution($owner);
    $before = DB::table('postings')->count();
    try {
        F::obligation($owner, $fixture, ['base_kind' => 'custom', 'custom_base' => '12', 'base_explanation' => '']);
        $this->fail('Custom base without its source explanation must be rejected.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('base_explanation');
        $this->assertDatabaseCount('purification_obligations', 0);
        expect(DB::table('postings')->count())->toBe($before);
    }
});

test('unverified or overdue thesis evidence produces attributed actions and closed opportunities are suppressed', function () {
    $facts = D::facts();
    $facts['opportunities'] = [['id' => '1', 'series_id' => 'synthetic-thesis', 'name' => 'Synthetic quality thesis', 'status' => 'watching', 'attributes' => ['review_on' => null, 'evidence' => ['offer' => ['verified' => false]]]]];
    $actions = DashboardRules::evaluate($facts, DashboardRules::defaults());
    expect($actions)->toHaveCount(1)->and($actions[0]['explanation'])->toContain('missing or unverified');
    $facts['opportunities'][0]['status'] = 'closed';
    expect(DashboardRules::evaluate($facts, DashboardRules::defaults()))->toBe([]);
});
