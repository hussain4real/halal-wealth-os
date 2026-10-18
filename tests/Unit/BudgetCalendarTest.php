<?php

use App\Domain\Planning\BudgetCalendar;
use App\Domain\Planning\PlanningInput;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('Qatar monthly and annual anchors clamp missing days without drifting', function (string $first, int $interval, string $month, ?string $expected) {
    $rule = (object) ['first_due_date' => $first, 'interval_months' => $interval, 'end_date' => null];
    expect(BudgetCalendar::due($rule, $month))->toBe($expected);
})->with([
    ['2026-01-31', 1, '2026-02', '2026-02-28'],
    ['2026-01-31', 1, '2026-03', '2026-03-31'],
    ['2024-02-29', 12, '2025-02', '2025-02-28'],
    ['2024-02-29', 12, '2028-02', '2028-02-29'],
    ['2026-01-31', 4, '2026-04', null],
    ['2026-01-31', 4, '2026-05', '2026-05-31'],
    ['2026-01-31', 0, '2026-02', null],
    ['2026-01-31', 0, '2026-01', '2026-01-31'],
]);

test('a rule end and future revision exclude only ineligible occurrences', function () {
    $rule = (object) ['first_due_date' => '2026-01-31', 'interval_months' => 1, 'end_date' => '2026-03-30'];
    expect(BudgetCalendar::due($rule, '2026-03'))->toBeNull()
        ->and(BudgetCalendar::due($rule, '2026-02', '2026-02-28'))->toBeNull()
        ->and(BudgetCalendar::due($rule, '2026-02', '2026-03-01'))->toBe('2026-02-28')
        ->and(BudgetCalendar::provision((object) ['interval_months' => 4, 'expected_minor' => '43117']))->toBe('10780')
        ->and(BudgetCalendar::provision((object) ['interval_months' => 0, 'expected_minor' => '43117']))->toBeNull();
});

test('planning dates accept future local dates but never normalize invalid dates', function (mixed $date) {
    expect(fn () => PlanningInput::date($date))->toThrow(ValidationException::class);
})->with(['2026-02-30', '0000-01-01', '9999-01-01', '2026-1-01', [['2026-10-18']]]);
