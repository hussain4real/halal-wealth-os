<?php

namespace App\Domain\Shariah;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class AssessmentRead
{
    /** @return array<string,mixed> */
    public function view(User $owner, string $assetId, string $cutoff, ?string $after = null): array
    {
        LedgerInput::instant($cutoff, 'cutoff');

        return PortfolioRead::coherent(function () use ($owner, $assetId, $cutoff, $after): array {
            $asset = LedgerInput::owned('portfolio_assets', $owner, $assetId);
            $date = substr($cutoff, 0, 10);
            $eligible = fn (Builder $q): Builder => $q->where('recorded_at', '<=', $cutoff)->where('effective_on', '<=', $date);
            $current = $eligible(DB::table('shariah_assessments')->where('owner_id', $owner->id)->where('asset_id', $assetId))
                ->whereNotExists(function (Builder $q) use ($cutoff, $date): void {
                    $q->selectRaw('1')->from('shariah_assessments as newer')->whereColumn('newer.owner_id', 'shariah_assessments.owner_id')
                        ->whereColumn('newer.series_id', 'shariah_assessments.series_id')->whereColumn('newer.version', '>', 'shariah_assessments.version')
                        ->where('newer.recorded_at', '<=', $cutoff)->where('newer.effective_on', '<=', $date);
                })->orderBy('id')->get();
            $claims = $current->where('provenance', 'source_claim');
            $statuses = $claims->pluck('classification')->unique();
            $conflict = $statuses->count() > 1;
            $overdue = $claims->contains(fn ($r): bool => $r->review_due_on !== null && $r->review_due_on < $date);
            $missingReview = $claims->contains(fn ($r): bool => $r->review_due_on === null);
            $classification = $claims->isEmpty() || $conflict ? 'pending_review' : $statuses->first();
            $supported = $classification === 'compliant' && ! $overdue && ! $missingReview;
            $history = $eligible(DB::table('shariah_assessments')->where('owner_id', $owner->id)->where('asset_family', $asset->family_reference));
            if ($after !== null) {
                $history->where('id', '<', LedgerInput::identifier($after));
            }
            $rows = $history->orderByDesc('id')->limit(21)->get();
            $otherVersion = $eligible(DB::table('shariah_assessments')->where('owner_id', $owner->id)->where('asset_family', $asset->family_reference)->where('asset_id', '<>', $assetId))->exists();

            return ['asset' => ['id' => $assetId, 'family_reference' => $asset->family_reference, 'name' => $asset->name, 'version' => (string) $asset->version, 'identifier' => $asset->identifier],
                'cutoff' => $cutoff, 'summary' => ['classification' => $classification, 'conflict' => $conflict, 'overdue' => $overdue, 'review_date_missing' => $missingReview,
                    'other_reference_evidence' => $otherVersion, 'source_count' => $claims->count(), 'guidance_state' => $supported ? 'recorded_compliant' : 'unresolved',
                    'guidance' => $supported ? 'Recorded sources currently classify this reference as compliant. Review their methodology and evidence before an investment decision; the application does not certify securities.' : 'Compliance evidence is unresolved. Review missing, conflicting, overdue or uncertain source claims before an investment decision.',
                    'factual_recording' => 'You can record an existing holding or actual investment event with any assessment status. Assessment changes never alter cash, holdings, book cost or valuation.'],
                'current' => $current->map(fn ($r): array => $this->row($r, $date))->all(),
                'history' => ['items' => $rows->take(20)->map(fn ($r): array => $this->row($r, $date))->all(), 'next' => $rows->count() > 20 ? (string) $rows[19]->id : null]];
        });
    }

    /** @return array<string,mixed> */
    public function row(stdClass $r, string $date): array
    {
        return ['id' => (string) $r->id, 'reference' => $r->reference, 'series_id' => $r->series_id, 'version' => (string) $r->version, 'supersedes_id' => $r->supersedes_id === null ? null : (string) $r->supersedes_id,
            'asset_id' => (string) $r->asset_id, 'asset_family' => $r->asset_family, 'provenance' => $r->provenance, 'provider' => $r->provider,
            'methodology_version' => $r->methodology_version, 'classification' => $r->classification, 'effective_on' => $r->effective_on, 'review_due_on' => $r->review_due_on,
            'overdue' => $r->review_due_on !== null && $r->review_due_on < $date, 'recorded_at' => LedgerInput::utc($r->recorded_at),
            'evidence_refs' => json_decode($r->evidence_refs, true, flags: JSON_THROW_ON_ERROR), 'source_url' => $r->source_url,
            'business_findings' => $r->business_findings, 'financial_findings' => $r->financial_findings, 'notes' => $r->notes, 'reason' => $r->reason,
            'actor_id' => (string) $r->actor_id, 'actor_is_admin' => (bool) $r->actor_is_admin];
    }
}
