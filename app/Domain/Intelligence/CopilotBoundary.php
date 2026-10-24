<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Reporting\Copilot\Tool;
use App\Domain\Reporting\Copilot\ToolQuery;
use App\Domain\Reporting\CopilotEvidence;
use App\Models\User;
use App\Support\Release\OwnerExport;
use App\Support\Release\OwnerExportLimit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use stdClass;
use Throwable;

final class CopilotBoundary
{
    public function __construct(private ToolProvider $provider, private CopilotEvidence $evidence, private int $exportLimit = OwnerExport::MAX_BYTES) {}

    /** Owner is deliberately absent from every public tool signature.
     * @return array<string,mixed> */
    public function page(): array
    {
        $owner = $this->owner();
        $control = DB::table('copilot_controls')->where('owner_id', $owner->id)->first();

        return ['enabled' => config('copilot.enabled') === true, 'provider_available' => $this->provider->available(),
            'consent' => (bool) ($control->consent ?? false), 'generation' => (string) ($control->generation ?? 0),
            'registry' => Tool::registry(), 'usage' => $this->usage($owner),
            'limits' => array_intersect_key(config('copilot'), array_flip(['requests_per_day', 'reserved_tokens_per_day', 'tokens_per_request', 'input_bytes', 'evidence_bytes', 'retained_requests'])),
            'history' => DB::table('copilot_requests')->where('owner_id', $owner->id)->orderByDesc('id')->limit(20)->get(['reference', 'lane', 'tool', 'status', 'reserved_tokens', 'recorded_at'])->all()];
    }

    public function consent(bool $enabled, string $generation): void
    {
        $owner = $this->owner();
        DB::transaction(function () use ($owner, $enabled, $generation): void {
            $this->lock($owner);
            $control = DB::table('copilot_controls')->where('owner_id', $owner->id)->first();
            if (! $enabled && $control === null) {
                return; // Already off; do not add a row at complete-export capacity.
            }
            $control ??= $this->control($owner);
            if ($enabled) {
                abort_unless(config('copilot.enabled') === true, 409, 'The copilot boundary is disabled.');
                abort_unless((string) $control->generation === $generation, 409, 'Consent changed. Refresh before enabling it.');
            }
            // Revocation is always possible, including at the retained-request cap.
            DB::table('copilot_controls')->where('owner_id', $owner->id)->update(['consent' => $enabled,
                'generation' => $control->generation + 1, 'updated_at' => DB::raw('clock_timestamp()')]);
            if ($enabled) {
                $this->headroom($owner);
            }
        });
    }

    /** Direct owner preview; does not call a provider.
     * @return array<string,mixed> */
    public function preview(string $reference, ToolQuery $query): array
    {
        $owner = $this->owner();
        $admitted = $this->admit($owner, $reference, 'preview', $query->tool);
        if (! $admitted) {
            return ['status' => 'already_charged', 'reference' => $reference];
        }

        return $this->execute($owner, $reference, $query);
    }

    /** Selection contract only; answer generation belongs to M7-P2.
     * @return array<string,mixed> */
    public function propose(string $reference, UntrustedInput $input): array
    {
        $owner = $this->owner();
        $admitted = $this->admit($owner, $reference, 'provider', null);
        if (! $admitted) {
            return ['status' => 'already_charged', 'reference' => $reference];
        }
        try {
            if (! $this->provider->available()) {
                throw new \RuntimeException('Unavailable');
            }
            $json = DB::transaction(function () use ($owner, $reference, $input): string {
                $this->lock($owner);
                $this->permit($owner, $reference);

                return $this->provider->propose($input, Tool::registry(), config('copilot.provider_output_bytes'));
            });
            if (strlen($json) > config('copilot.provider_output_bytes')) {
                throw new \RuntimeException('Oversized proposal');
            }
            $proposal = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($proposal)) {
                throw new \RuntimeException('Malformed proposal');
            }
            $query = ToolQuery::parse($proposal);
        } catch (Throwable) {
            $this->finish($owner, $reference, 'unavailable');

            return ['status' => 'unavailable', 'reference' => $reference];
        }

        return $this->execute($owner, $reference, $query);
    }

    /** @return array<string,mixed> */
    private function execute(User $owner, string $reference, ToolQuery $query): array
    {
        try {
            // Recheck consent after provider return; it cannot cache or carry authority.
            return DB::transaction(function () use ($owner, $reference, $query): array {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->lock($owner);
                $this->permit($owner, $reference);
                $result = $this->evidence->read($owner, $query)->jsonSerialize();
                if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > config('copilot.evidence_bytes')) {
                    throw new \RuntimeException('Evidence exceeds disclosure ceiling');
                }
                $this->finish($owner, $reference, 'complete', $query->tool);

                return ['status' => 'complete', 'reference' => $reference, 'evidence' => $result];
            });
        } catch (Throwable) {
            $this->finish($owner, $reference, 'rejected');

            return ['status' => 'unavailable', 'reference' => $reference];
        }
    }

    private function admit(User $owner, string $reference, string $lane, ?Tool $tool): bool
    {
        Validator::make(['reference' => $reference], ['reference' => ['required', 'uuid']])->validate();
        $this->configuration();

        return DB::transaction(function () use ($owner, $reference, $lane, $tool): bool {
            $this->lock($owner);
            $control = $this->allowed($owner);
            if (DB::table('copilot_requests')->where('owner_id', $owner->id)->where('reference', $reference)->exists()) {
                return false;
            }
            $at = $this->instant();
            $usage = $this->usage($owner, $at);
            abort_if($usage['retained'] >= config('copilot.retained_requests') || $usage['requests_today'] >= config('copilot.requests_per_day')
                || config('copilot.reserved_tokens_per_day') < $usage['reserved_tokens_today'] + config('copilot.tokens_per_request'), 429, 'Copilot usage limit reached. Core financial pages remain available.');
            DB::table('copilot_requests')->insert(['owner_id' => $owner->id, 'reference' => $reference,
                'lane' => $lane, 'consent_generation' => $control->generation, 'tool' => $tool?->value,
                'reserved_tokens' => config('copilot.tokens_per_request'), 'recorded_at' => $at]);
            $this->headroom($owner);

            return true;
        });
    }

    private function finish(User $owner, string $reference, string $status, ?Tool $tool = null): void
    {
        $changes = ['status' => $status, 'finished_at' => DB::raw('clock_timestamp()')];
        if ($tool !== null) {
            $changes['tool'] = $tool->value;
        }
        DB::table('copilot_requests')->where('owner_id', $owner->id)->where('reference', $reference)->where('status', 'pending')
            ->update($changes);
    }

    private function lock(User $owner): void
    {
        // Same owner-wide export headroom lock used by retained domain writers.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['hwo:posting:owner:'.$owner->id]);
    }

    private function headroom(User $owner): void
    {
        try {
            $bytes = app(OwnerExport::class)->bytes($owner);
        } catch (OwnerExportLimit) {
            abort(429, 'Complete retained export capacity reached. Consent revocation remains available.');
        }
        // At most512 charges can remain incomplete. Fixed-enum completion fields
        // need less than64KiB total, including unconditional control revocation.
        abort_if($bytes > min(OwnerExport::MAX_BYTES, $this->exportLimit) - 65536, 429, 'Complete retained export capacity reached. Consent revocation remains available.');
    }

    private function owner(): User
    {
        $owner = Auth::user();
        abort_unless($owner instanceof User && $owner->hasVerifiedEmail()
            && (! app()->isProduction() || $owner->hasEnabledTwoFactorAuthentication()), 403);

        return $owner;
    }

    private function allowed(User $owner): stdClass
    {
        $control = DB::table('copilot_controls')->where('owner_id', $owner->id)->lockForUpdate()->first();
        abort_unless(config('copilot.enabled') === true && $control?->consent === true, 403, 'Explicit current consent and an enabled boundary are required.');

        return $control;
    }

    private function permit(User $owner, string $reference): void
    {
        $control = $this->allowed($owner);
        $request = DB::table('copilot_requests')->where('owner_id', $owner->id)->where('reference', $reference)->firstOrFail();
        abort_unless($request->status === 'pending' && $request->consent_generation === $control->generation, 403, 'The request consent epoch ended.');
    }

    private function control(User $owner): stdClass
    {
        DB::table('copilot_controls')->insertOrIgnore(['owner_id' => $owner->id]);

        return DB::table('copilot_controls')->where('owner_id', $owner->id)->firstOrFail();
    }

    /** @return array{requests_today:int,reserved_tokens_today:int,retained:int} */
    private function usage(User $owner, ?string $at = null): array
    {
        $at ??= $this->instant();
        $q = DB::table('copilot_requests')->where('owner_id', $owner->id);
        $retained = $q->count();
        $q->whereRaw("recorded_at >= (date_trunc('day', ?::timestamptz AT TIME ZONE 'UTC') AT TIME ZONE 'UTC')", [$at]);

        return ['requests_today' => $q->count(), 'reserved_tokens_today' => (int) $q->sum('reserved_tokens'), 'retained' => $retained];
    }

    private function instant(): string
    {
        // Read after the owner lock. PostgreSQL now() would retain transaction
        // start time even if that lock waited across a UTC day boundary.
        $at = DB::selectOne('SELECT clock_timestamp()::text AS instant')->instant;
        if (! is_string($at)) {
            throw new \RuntimeException('Database admission clock is unavailable.');
        }

        return $at;
    }

    private function configuration(): void
    {
        foreach (['requests_per_day', 'reserved_tokens_per_day', 'tokens_per_request', 'input_bytes', 'provider_output_bytes', 'evidence_bytes', 'retained_requests'] as $key) {
            $v = config('copilot.'.$key);
            abort_unless(is_int($v) && $v > 0 && $v <= ($key === 'retained_requests' ? 512 : 65536), 503, 'Invalid copilot limits.');
        }
        $bytes = config('copilot.input_bytes') + config('copilot.provider_output_bytes') + config('copilot.evidence_bytes')
            + strlen(json_encode(Tool::registry(), JSON_THROW_ON_ERROR)) + 512;
        // One token per UTF-8 byte is a conservative reservation, not measured usage.
        abort_unless($bytes <= config('copilot.tokens_per_request'), 503, 'Copilot reservation does not cover configured disclosure limits.');
    }
}
