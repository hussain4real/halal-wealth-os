<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use Carbon\CarbonImmutable;
use stdClass;

final class GoalProjection
{
    /** @return array<string, mixed> */
    public function calculate(stdClass $goal, ?string $backed, ?string $essential, ?string $today = null): array
    {
        $today ??= BudgetCalendar::today();
        if ($backed === null) {
            return ['status' => 'backing_unavailable', 'remaining_minor' => null, 'deposits' => null, 'completion_date' => null, 'date_reason' => 'backing_unavailable', 'coverage_months' => null, 'coverage_reason' => 'backing_unavailable', 'percent' => null, 'target_date_status' => 'unavailable'];
        }
        $backed = PlanningInput::minor($backed);
        $target = PlanningInput::minor((string) $goal->target_minor);
        $contribution = PlanningInput::minor((string) $goal->contribution_minor);
        $essential = $essential === null ? null : PlanningInput::minor($essential);
        $gap = bcsub($target, $backed, 0);
        if (bccomp($gap, '0', 0) < 0) {
            $gap = '0';
        }
        $status = $gap === '0' ? 'reached' : ($goal->policy === 'variable' ? 'variable' : (bccomp($contribution, '0', 0) === 0 ? 'unreachable' : 'projected'));
        $count = $gap === '0' ? '0' : null;
        if ($status === 'projected') {
            $count = bcdiv($gap, $contribution, 0);
            if (bcmod($gap, $contribution, 0) !== '0') {
                $count = bcadd($count, '1', 0);
            }
        }
        $date = null;
        $dateReason = $status === 'projected' ? 'first_deposit_missing' : $status;
        if ($status === 'projected' && $goal->first_deposit !== null) {
            if ($goal->first_deposit < $today) {
                $dateReason = 'missed_plan_needs_update';
            } elseif (bccomp($count, '119975', 0) > 0) {
                $dateReason = 'date_out_of_range';
            } else {
                $candidate = CarbonImmutable::parse($goal->first_deposit, BudgetCalendar::ZONE)->addMonthsNoOverflow((int) $count - 1)->toDateString();
                if (strlen($candidate) !== 10 || $candidate > '9998-12-31') {
                    $dateReason = 'date_out_of_range';
                } else {
                    $date = $candidate;
                    $dateReason = null;
                }
            }
        }
        $percent = bcdiv(bcmul($backed, '100', 0), $target, 2);

        return ['status' => $status, 'remaining_minor' => $gap, 'deposits' => $count, 'completion_date' => $date, 'date_reason' => $dateReason,
            'coverage_months' => $essential !== null && bccomp($essential, '0', 0) > 0 ? bcdiv($backed, $essential, 2) : null,
            'coverage_reason' => $essential === null ? 'essential_unavailable' : ($essential === '0' ? 'essential_zero' : null),
            'percent' => $percent, 'target_date_status' => $gap === '0' ? 'reached' : ($goal->target_date === null || $date === null ? 'unavailable' : ($date <= $goal->target_date ? 'on_plan' : 'after_target'))];
    }
}
