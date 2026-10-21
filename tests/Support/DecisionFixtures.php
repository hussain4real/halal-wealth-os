<?php

namespace Tests\Support;

use App\Domain\Decisions\DecisionService;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class DecisionFixtures
{
    /** @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public static function input(array $changes = []): array
    {
        return [...['prior_id' => '', 'name' => 'Synthetic opportunity', 'status' => 'planned', 'currency' => 'NGN', 'asset_id' => '', 'target' => '1000', 'maximum' => '1200',
            'tranches' => json_encode([['key' => 'initial', 'label' => 'Initial proposal', 'amount' => '700', 'condition' => 'Review official terms'], ['key' => 'reserve', 'label' => 'Conditional reserve', 'amount' => '300', 'condition' => 'Separate later review']], JSON_THROW_ON_ERROR),
            'rationale' => 'Synthetic long-term thesis', 'risks' => 'Synthetic concentration risk', 'horizon' => 'Synthetic multi-year horizon', 'review_triggers' => 'Terms or risk changes', 'review_on' => now('Asia/Qatar')->addMonth()->toDateString(), 'reason' => 'Synthetic decision record'], ...$changes];
    }

    /** @param array<string,mixed> $changes */
    public static function plan(User $owner, array $changes = [], ?string $key = null): stdClass
    {
        return app(DecisionService::class)->plan($owner, $key ?? (string) Str::uuid(), self::input($changes));
    }

    /** @param array<string,mixed> $changes */
    public static function reserve(User $owner, stdClass $plan, stdClass $goal, array $changes = [], ?string $key = null): stdClass
    {
        return app(DecisionService::class)->reserve($owner, $key ?? (string) Str::uuid(), [...['opportunity_id' => (string) $plan->id, 'tranche_key' => 'initial', 'goal_id' => (string) $goal->id, 'amount' => '500', 'reason' => 'Synthetic protected plan cash'], ...$changes]);
    }

    /** @param array<string,mixed> $changes */
    public static function link(User $owner, stdClass $plan, stdClass $event, array $changes = [], ?string $key = null): stdClass
    {
        return app(DecisionService::class)->link($owner, $key ?? (string) Str::uuid(), [...['opportunity_id' => (string) $plan->id, 'tranche_key' => 'initial', 'event_id' => (string) $event->id, 'reason' => 'Synthetic attribution to original thesis'], ...$changes]);
    }
}
