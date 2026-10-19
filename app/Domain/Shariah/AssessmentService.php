<?php

namespace App\Domain\Shariah;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class AssessmentService
{
    public const FIELDS = ['context', 'supersedes_id', 'provenance', 'provider', 'methodology_version', 'classification', 'effective_on', 'review_due_on', 'evidence_refs', 'source_url', 'business_findings', 'financial_findings', 'notes', 'reason'];

    public function context(User $owner, stdClass $asset): string
    {
        $latest = DB::table('portfolio_assets')->where('owner_id', $owner->id)->where('family_reference', $asset->family_reference)->max('version');

        return hash_hmac('sha256', json_encode([$owner->id, (string) $asset->id, (string) $latest], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string,mixed> $input */
    public function record(User $owner, string $assetId, string $key, array $input): stdClass
    {
        return DB::transaction(function () use ($owner, $assetId, $key, $input): stdClass {
            LedgerInput::lock($owner);
            PortfolioInput::only($input, self::FIELDS);
            $payload = ['asset_id' => $assetId, ...$input];
            // A committed attempt recovers before stale form or revision admission.
            if ($row = LedgerInput::cached('shariah_assessments', $owner, $key, $payload)) {
                return $row;
            }
            $asset = LedgerInput::owned('portfolio_assets', $owner, $assetId);
            if (! is_string($input['context'] ?? null) || ! hash_equals($this->context($owner, $asset), $input['context'])) {
                LedgerInput::fail('context', 'The asset reference changed. Check saved history, then reload this form before recording new evidence.');
            }
            $priorId = $input['supersedes_id'] ?? '';
            $prior = $priorId === '' ? null : LedgerInput::owned('shariah_assessments', $owner, LedgerInput::identifier($priorId));
            if ($prior !== null && ((string) $prior->asset_id !== $assetId || DB::table('shariah_assessments')->where('supersedes_id', $prior->id)->exists())) {
                LedgerInput::fail('supersedes_id', 'This assessment has changed or belongs to another reference. Review history and revise its latest version.');
            }
            $provenance = $input['provenance'] ?? null;
            $classification = $input['classification'] ?? null;
            if (! in_array($provenance, ['source_claim', 'personal_note', 'ai_suggestion'], true)) {
                LedgerInput::fail('provenance', 'Choose an attributed source claim, personal note or retained AI suggestion.');
            }
            if (! in_array($classification, ['compliant', 'non_compliant', 'questionable', 'pending_review'], true)) {
                LedgerInput::fail('classification', 'Choose one of the four recorded classifications.');
            }
            $provider = $this->text($input['provider'] ?? null, 'provider', 180, true);
            $method = $this->text($input['methodology_version'] ?? '', 'methodology_version', 180) ?? '';
            if ($prior !== null && ($prior->provider !== $provider || $prior->provenance !== $provenance)) {
                LedgerInput::fail('provider', 'A revision retains its source and provenance. Record a separate source instead of replacing another authority.');
            }
            $refs = $input['evidence_refs'] ?? [];
            if (! is_array($refs) || ! array_is_list($refs) || count($refs) > 12) {
                LedgerInput::fail('evidence_refs', 'Record at most twelve evidence references.');
            }
            $refs = array_map(fn ($ref): string => $this->text($ref, 'evidence_refs', 1000, true) ?? '', $refs);
            if ($classification !== 'pending_review' && ($provenance !== 'source_claim' || $refs === [] || $method === '')) {
                LedgerInput::fail('classification', 'A non-pending source classification requires referenced evidence and its methodology version. Personal notes and AI suggestions remain pending.');
            }
            $effective = LedgerInput::date($input['effective_on'] ?? null, 'effective_on');
            if ($effective > '9998-12-31') {
                LedgerInput::fail('effective_on', 'Use an effective date through 9998-12-31.');
            }
            if ($prior !== null && $effective < $prior->effective_on) {
                LedgerInput::fail('effective_on', 'A revision cannot take effect before its predecessor. Its previous evidence remains available as of earlier dates.');
            }
            $dueValue = $input['review_due_on'] ?? '';
            $due = $dueValue === '' ? null : $this->reviewDate($dueValue);
            if ($due !== null && $due < $effective) {
                LedgerInput::fail('review_due_on', 'The review date must be on or after the effective date.');
            }
            $url = $this->text($input['source_url'] ?? '', 'source_url', 2000);
            if ($url !== null && (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
                LedgerInput::fail('source_url', 'Use a complete HTTPS source link without embedded credentials, or leave it blank. Links are never fetched automatically.');
            }
            $data = ['owner_id' => $owner->id, 'actor_id' => $owner->id, 'actor_is_admin' => User::query()->findOrFail($owner->id)->is_admin,
                'asset_id' => $asset->id, 'asset_family' => $asset->family_reference, 'asset_currency' => $asset->currency, 'asset_kind' => $asset->kind,
                'reference' => (string) Str::ulid(), 'series_id' => $prior->series_id ?? (string) Str::ulid(), 'version' => $prior === null ? 1 : $prior->version + 1,
                'supersedes_id' => $prior?->id, 'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($payload),
                'provenance' => $provenance, 'provider' => $provider, 'methodology_version' => $method, 'classification' => $classification,
                'effective_on' => $effective, 'review_due_on' => $due, 'evidence_refs' => json_encode($refs, JSON_THROW_ON_ERROR), 'source_url' => $url,
                'business_findings' => $this->text($input['business_findings'] ?? '', 'business_findings', 4000),
                'financial_findings' => $this->text($input['financial_findings'] ?? '', 'financial_findings', 4000), 'notes' => $this->text($input['notes'] ?? '', 'notes', 4000),
                'reason' => $this->text($input['reason'] ?? null, 'reason', 180, true), 'recorded_at' => now('UTC')->format('Y-m-d H:i:sP')];
            $id = DB::table('shariah_assessments')->insertGetId($data);

            return DB::table('shariah_assessments')->where('id', $id)->sole();
        });
    }

    private function reviewDate(mixed $value): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if (! is_string($value) || ! $date || $date->format('Y-m-d') !== $value || $value < '0001-01-01' || $value > '9998-12-31') {
            LedgerInput::fail('review_due_on', 'Use a valid review date; future review dates are allowed.');
        }

        return $value;
    }

    private function text(mixed $value, string $field, int $max, bool $required = false): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $max || ($required && trim($value) === '')) {
            LedgerInput::fail($field, 'Use '.($required ? 'nonempty ' : '').'text up to '.$max.' characters.');
        }

        return trim($value) === '' ? null : trim($value);
    }
}
