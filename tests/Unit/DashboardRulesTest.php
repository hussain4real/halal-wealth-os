<?php

use App\Domain\Reporting\DashboardRules;
use Tests\Support\DashboardFixtures as D;
use Tests\TestCase;

uses(TestCase::class);

test('exact allocation comparison uses full signed denominator and explicit tolerance', function () {
    $facts = D::facts();
    $settings = DashboardRules::settings(D::settings(['allocation' => '1', 'targets' => '[{"currency":"QAR","target":"50","tolerance":"5"},{"currency":"USD","target":"50","tolerance":"5"}]']));
    $a = DashboardRules::evaluate($facts, $settings);
    expect($a)->toHaveCount(2)->and($a[0]['explanation'])->toContain('60.0000%')->and($a[0]['basis']['target']['target'])->toBe('50');
    expect(DashboardRules::evaluate($facts, $settings))->toBe($a);
});
test('partial or nonpositive allocation and missing configured currencies do not claim reassuring weights', function (array $changes) {
    $facts = D::facts();
    $facts['valuation']['ending']['allocation'] = [...$facts['valuation']['ending']['allocation'], ...$changes];
    $s = DashboardRules::settings(D::settings(['allocation' => '1', 'targets' => '[{"currency":"QAR","target":"100","tolerance":"0"}]']));
    $a = DashboardRules::evaluate($facts, $s);
    expect($a)->toHaveCount(1)->and($a[0]['subject'])->toBe('allocation:coverage')->and($a[0]['explanation'])->toContain('partial subtotal cannot prove');
})->with([[['coverage' => 'partial']], [['denominator_minor' => '0']], [['denominator_minor' => '-10']], [[]]]);
test('disabled and unconfigured allocation rules produce no invented target actions', function () {
    expect(DashboardRules::evaluate(D::facts(), DashboardRules::defaults()))->toBe([]);
    $s = DashboardRules::defaults();
    $s['enabled'] = [];
    expect(DashboardRules::evaluate(D::facts(), $s))->toBe([]);
});
test('configured emergency standing order produces no unsolicited setup or inferred failed-transfer prompt', function () {
    $facts = D::facts();
    $facts['goals'] = [['id' => '1', 'root_id' => '1', 'name' => 'Synthetic emergency reserve', 'closed' => false, 'projection' => ['date_reason' => null], 'first_deposit' => null, 'standing_order' => true]];
    expect(DashboardRules::evaluate($facts, DashboardRules::defaults()))->toBe([]);
});
test('past first-contribution date explains a plan review rather than asserting a failed transfer', function () {
    $facts = D::facts();
    $facts['goals'] = [['id' => '1', 'root_id' => '1', 'name' => 'Synthetic emergency reserve', 'closed' => false, 'projection' => ['date_reason' => 'missed_plan_needs_update'], 'first_deposit' => '2026-10-01', 'standing_order' => true, 'backed' => null]];
    $a = DashboardRules::evaluate($facts, DashboardRules::defaults());
    expect($a)->toHaveCount(1)->and($a[0]['explanation'])->toContain('does not prove that a transfer failed')->and($a[0]['title'])->toContain('Review funding plan');
    $facts['planning_date'] = '2026-10-22';
    expect(DashboardRules::evaluate($facts, DashboardRules::defaults())[0]['key'])->toBe($a[0]['key']);
});
