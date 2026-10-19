<?php

namespace Tests\Support;

use App\Domain\Shariah\AssessmentService;
use App\Models\User;
use Illuminate\Support\Str;
use stdClass;

final class AssessmentFixtures
{
    /** @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public static function input(User $owner, stdClass $asset, array $changes = []): array
    {
        return [...['context' => app(AssessmentService::class)->context($owner, $asset), 'supersedes_id' => '', 'provenance' => 'source_claim',
            'provider' => 'Synthetic source A', 'methodology_version' => 'Synthetic methodology v1', 'classification' => 'compliant',
            'effective_on' => now('UTC')->toDateString(), 'review_due_on' => now('UTC')->addMonth()->toDateString(),
            'evidence_refs' => ['Synthetic retained report, section 3'], 'source_url' => 'https://example.com/synthetic-report',
            'business_findings' => 'Synthetic business activity review', 'financial_findings' => 'Synthetic sourced findings; no application thresholds',
            'notes' => 'Synthetic personal context', 'reason' => 'Synthetic assessment capture'], ...$changes];
    }

    /** @param array<string,mixed> $changes */
    public static function record(User $owner, stdClass $asset, array $changes = [], ?string $key = null): stdClass
    {
        return app(AssessmentService::class)->record($owner, (string) $asset->id, $key ?? (string) Str::uuid(), self::input($owner, $asset, $changes));
    }
}
