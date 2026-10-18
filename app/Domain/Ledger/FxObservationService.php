<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final class FxObservationService
{
    /** @param array<string, mixed> $input */
    public function record(User $owner, string $key, array $input, string $purpose = 'reporting'): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $input, $purpose): stdClass {
            LedgerInput::lock($owner);
            foreach (array_diff(array_keys($input), ['base_currency', 'quote_currency', 'value', 'source', 'observed_at', 'effective_at', 'supersedes_id', 'correction_reason']) as $field) {
                LedgerInput::fail($field, 'This field cannot be submitted.');
            }
            $base = is_string($input['base_currency'] ?? null) ? Currency::tryFrom($input['base_currency']) : null;
            $quote = is_string($input['quote_currency'] ?? null) ? Currency::tryFrom($input['quote_currency']) : null;
            if ($base === null || $quote === null || $base === $quote || ! in_array($purpose, ['reporting', 'transaction'], true)) {
                LedgerInput::fail('quote_currency', 'Choose two different supported currencies and an explicit direction.');
            }
            try {
                $value = FxMath::rate($input['value'] ?? null)->value;
            } catch (InvalidArgumentException $error) {
                LedgerInput::fail('value', $error->getMessage());
            }
            $prior = empty($input['supersedes_id']) ? null : LedgerInput::owned('fx_observations', $owner, LedgerInput::identifier($input['supersedes_id']), true);
            if ($prior !== null && ($prior->base_currency !== $base->value || $prior->quote_currency !== $quote->value || $prior->purpose !== $purpose)) {
                LedgerInput::fail('supersedes_id', 'A revision must retain its owned pair, direction and purpose.');
            }
            $payload = ['purpose' => $purpose, 'base_currency' => $base->value, 'quote_currency' => $quote->value, 'value' => $value,
                'source' => LedgerInput::text($input['source'] ?? null, 'source'), 'observed_at' => LedgerInput::instant($input['observed_at'] ?? null, 'observed_at'),
                'effective_at' => LedgerInput::instant($input['effective_at'] ?? null, 'effective_at'), 'supersedes_id' => $prior?->id,
                'correction_reason' => $prior === null ? null : LedgerInput::text($input['correction_reason'] ?? null, 'correction_reason')];
            if ($cached = LedgerInput::cached('fx_observations', $owner, $key, $payload)) {
                return $cached;
            }
            if ($prior !== null && DB::table('fx_observations')->where('supersedes_id', $prior->id)->exists()) {
                LedgerInput::fail('supersedes_id', 'This version already has a revision. Open its latest version.');
            }
            $id = DB::table('fx_observations')->insertGetId($payload + ['owner_id' => $owner->id, 'idempotency_key' => $key, 'payload_hash' => LedgerInput::hash($payload),
                'series_id' => $prior === null ? (string) Str::ulid() : $prior->series_id, 'version' => $prior === null ? 1 : $prior->version + 1]);

            return LedgerInput::owned('fx_observations', $owner, (string) $id);
        });
    }
}
