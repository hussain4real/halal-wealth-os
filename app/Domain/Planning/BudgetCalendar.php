<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use Carbon\CarbonImmutable;
use stdClass;

final class BudgetCalendar
{
    public const string ZONE = 'Asia/Qatar';

    public static function today(): string
    {
        return now(self::ZONE)->toDateString();
    }

    public static function due(stdClass $rule, string $month, ?string $nextCutover = null): ?string
    {
        PlanningInput::month($month);
        $first = CarbonImmutable::parse($rule->first_due_date, self::ZONE);
        $target = CarbonImmutable::parse($month.'-01', self::ZONE);
        $months = ((int) $target->format('Y') - (int) $first->format('Y')) * 12 + (int) $target->format('m') - (int) $first->format('m');
        if ($months < 0 || ($rule->interval_months === 0 ? $months !== 0 : $months % $rule->interval_months !== 0)) {
            return null;
        }
        $date = $first->addMonthsNoOverflow($months)->toDateString();
        if ($date < $rule->first_due_date || ($rule->end_date !== null && $date > $rule->end_date) || ($nextCutover !== null && $date >= $nextCutover)) {
            return null;
        }

        return $date;
    }

    public static function provision(stdClass $rule): ?string
    {
        if ($rule->interval_months === 0) {
            return null;
        }
        $divisor = (string) $rule->interval_months;
        $whole = bcdiv((string) $rule->expected_minor, $divisor, 0);

        return bcmod((string) $rule->expected_minor, $divisor, 0) === '0' ? $whole : bcadd($whole, '1', 0);
    }
}
