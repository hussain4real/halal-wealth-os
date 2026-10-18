<?php

namespace Tests\Support;

use App\Domain\Planning\BudgetService;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class BudgetFixtures
{
    public static function category(User $owner, array $changes = []): stdClass
    {
        return app(BudgetService::class)->category($owner, 'category-'.Str::ulid(), [...self::categoryInput(), ...$changes]);
    }

    public static function categoryInput(): array
    {
        return ['name' => 'Synthetic family support', 'direction' => 'expense', 'normal_budget' => '1', 'emergency_essential' => '0'];
    }

    public static function input(stdClass $category, array $changes = []): array
    {
        // Anonymous invariant-preserving public translation; original F06 stays private.
        return [...['category_id' => (string) $category->id, 'name' => 'Synthetic monthly support', 'currency' => 'QAR', 'amount' => '147.25',
            'cadence' => 'monthly', 'interval_months' => '', 'first_due_date' => now('Asia/Qatar')->toDateString(), 'end_date' => '',
            'salary' => '0', 'standing_order' => '0', 'provision_start_date' => ''], ...$changes];
    }

    public static function rule(User $owner, stdClass $category, array $changes = []): stdClass
    {
        return app(BudgetService::class)->schedule($owner, 'schedule-'.Str::ulid(), self::input($category, $changes));
    }
}
