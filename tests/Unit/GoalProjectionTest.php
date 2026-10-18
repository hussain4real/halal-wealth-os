<?php

use App\Domain\Planning\GoalProjection;

function syntheticGoal(array $changes = []): stdClass
{
    return (object) [...['target_minor' => '2400000', 'policy' => 'fixed_monthly', 'contribution_minor' => '160000', 'first_deposit' => null, 'target_date' => null], ...$changes];
}

test('anonymous F05 translation has twelve exact deposits and independent unavailable coverage date', function () {
    $r = (new GoalProjection)->calculate(syntheticGoal(), '480000', null, '2026-10-18');
    expect($r['remaining_minor'])->toBe('1920000')->and($r['deposits'])->toBe('12')->and($r['completion_date'])->toBeNull()->and($r['coverage_months'])->toBeNull();
});
test('zero variable reached and unavailable projections stay distinct', function (array $changes, ?string $cash, string $status, ?string $deposits) {
    $r = (new GoalProjection)->calculate(syntheticGoal($changes), $cash, null, '2026-10-18');
    expect($r['status'])->toBe($status)->and($r['deposits'])->toBe($deposits);
})->with([
    [['contribution_minor' => '0'], '480000', 'unreachable', null],
    [['contribution_minor' => '0'], '2400000', 'reached', '0'],
    [['policy' => 'variable'], '2500000', 'reached', '0'],
    [['policy' => 'variable'], '480000', 'variable', null],
    [[], null, 'backing_unavailable', null],
]);
test('exact ceil and coverage use no float or rounded-up protection', function () {
    $r = (new GoalProjection)->calculate(syntheticGoal(['target_minor' => '9223372036854775807', 'contribution_minor' => '1']), '1', '3', '2026-10-18');
    expect($r['deposits'])->toBe('9223372036854775806')->and($r['coverage_months'])->toBe('0.33');
    $r = (new GoalProjection)->calculate(syntheticGoal(['target_minor' => '1001', 'contribution_minor' => '100']), '0', '0', '2026-10-18');
    expect($r['deposits'])->toBe('11')->and($r['coverage_months'])->toBeNull()->and($r['coverage_reason'])->toBe('essential_zero');
});
test('configured Qatar month-end dates preserve day anchor and missed dates need update', function () {
    $g = syntheticGoal(['target_minor' => '300', 'contribution_minor' => '100', 'first_deposit' => '2027-01-31', 'target_date' => '2027-03-30']);
    $r = (new GoalProjection)->calculate($g, '0', '100', '2026-10-18');
    expect($r['completion_date'])->toBe('2027-03-31')->and($r['target_date_status'])->toBe('after_target');
    $r = (new GoalProjection)->calculate($g, '0', '100', '2027-02-01');
    expect($r['completion_date'])->toBeNull()->and($r['date_reason'])->toBe('missed_plan_needs_update');
});
test('completion calendar refuses overflow without losing exact deposit count', function () {
    $r = (new GoalProjection)->calculate(syntheticGoal(['first_deposit' => '9998-12-31']), '480000', '100', '2026-10-18');
    expect($r['deposits'])->toBe('12')->and($r['completion_date'])->toBeNull()->and($r['date_reason'])->toBe('date_out_of_range');
});
