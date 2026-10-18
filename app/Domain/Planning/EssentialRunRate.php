<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Same month-end native policy as BudgetView; no activity payload or cash inference. */
final class EssentialRunRate
{
    /** @return array<string, string|null> */
    public function month(User $owner, string $month): array
    {
        PlanningInput::month($month);
        $end = CarbonImmutable::parse($month.'-01', BudgetCalendar::ZONE)->endOfMonth()->toDateString();
        $rows = DB::select(<<<'SQL'
SELECT s.currency, sum(ceil(s.expected_minor::numeric / nullif(s.interval_months,0)))::text AS minor
FROM planning_schedules s JOIN planning_categories c ON c.id=s.category_id AND c.owner_id=s.owner_id
WHERE s.owner_id=? AND c.direction='expense' AND c.emergency_essential AND s.interval_months>0
 AND s.provision_start_date<=? AND (s.end_date IS NULL OR s.end_date>=?)
 AND NOT EXISTS(SELECT 1 FROM planning_schedules n WHERE n.supersedes_id=s.id AND n.cutover_date<=?)
GROUP BY s.currency
SQL, [$owner->id, $end, $end, $end]);
        $result = [];
        foreach ($rows as $row) {
            $money = BudgetActivity::money($row->currency, $row->minor);
            $result[$row->currency] = $money['available'] ? $row->minor : null;
        }

        return $result;
    }
}
