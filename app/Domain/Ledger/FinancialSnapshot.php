<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final class FinancialSnapshot
{
    public const string POLICY = 'retrospective-UTC-businessdate-v1;direct-7d;sum-rounded-rows-v1';

    /** @return array<string, mixed> */
    public function capture(User $owner, Currency $base, string $cutoff, ?string $accountId = null): array
    {
        $cutoff = LedgerInput::instant($cutoff, 'cutoff');
        if ($accountId !== null) {
            LedgerInput::owned('accounts', $owner, $accountId);
        }
        // All accounts, journal identities, opening states and chosen rates are
        // read by ONE PostgreSQL statement. No per-account second reads.
        $rows = DB::select(<<<'SQL'
WITH parameters AS (SELECT ?::bigint AS owner_id, ?::timestamptz AS cutoff, ?::text AS base, ?::bigint AS account_filter)
SELECT a.id::text AS account_id,a.name,a.type,a.currency_code,a.archived_at,
 i.id::text AS intent_id,i.amount_minor::text AS opening_minor,opening.id::text AS opening_journal_id,
 opening.effective_date::text AS opening_effective_date,
 CASE WHEN i.id IS NULL THEN NULL WHEN opening.id IS NULL THEN 'awaiting_posting'
 WHEN opening.effective_date > (parameters.cutoff AT TIME ZONE 'UTC')::date THEN 'outside_cutoff'
 WHEN EXISTS(SELECT 1 FROM journal_entries r WHERE r.reverses_journal_id = opening.id AND r.owner_id = a.owner_id AND r.status IN ('posted','reversed') AND r.effective_date <= (parameters.cutoff AT TIME ZONE 'UTC')::date) THEN 'reversed' ELSE 'posted' END AS opening_state,
 movements.minor,movements.journals,
 q.id::text AS rate_id,q.value::text AS rate_value,q.source AS rate_source,q.observed_at,q.effective_at,q.series_id,q.version,
 to_char(statement_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"') AS captured_at
FROM parameters JOIN accounts a ON a.owner_id = parameters.owner_id
LEFT JOIN opening_balance_intents i ON i.account_id = a.id
LEFT JOIN opening_balance_postings link ON link.opening_intent_id = i.id
LEFT JOIN journal_entries opening ON opening.id = link.journal_id AND opening.owner_id = a.owner_id AND opening.status IN ('posted','reversed')
LEFT JOIN LATERAL (
 SELECT coalesce(sum(p.amount_minor::numeric),0)::text AS minor,
 coalesce(jsonb_agg(jsonb_build_object('posting_id',p.id::text,'journal_id',j.id::text,'minor',p.amount_minor::text,'effective_date',j.effective_date::text) ORDER BY p.id),'[]'::jsonb) AS journals
 FROM postings p JOIN journal_entries j ON j.id = p.journal_id
 WHERE p.account_id = a.id AND p.owner_id = a.owner_id AND j.owner_id = a.owner_id AND j.status IN ('posted','reversed') AND j.effective_date <= (parameters.cutoff AT TIME ZONE 'UTC')::date
) movements ON TRUE
LEFT JOIN LATERAL (
 SELECT * FROM fx_observations rate WHERE rate.owner_id = a.owner_id AND rate.purpose = 'reporting'
 AND rate.base_currency = a.currency_code AND rate.quote_currency = parameters.base
 AND rate.observed_at <= parameters.cutoff AND rate.effective_at <= parameters.cutoff
 ORDER BY rate.effective_at DESC,rate.observed_at DESC,rate.id DESC LIMIT 1
) q ON TRUE
WHERE a.system_role IS NULL AND a.type IN ('bank','cash','investment','liability') AND (parameters.account_filter IS NULL OR a.id = parameters.account_filter)
ORDER BY a.id
SQL, [$owner->id, $cutoff, $base->value, $accountId]);
        $components = [];
        $subtotal = '0';
        $covered = 0;
        foreach ($rows as $row) {
            $currency = Currency::from($row->currency_code);
            $reason = match ($row->opening_state) {
                'awaiting_posting' => 'opening_pending', 'outside_cutoff' => 'opening_after_cutoff', default => null,
            };
            $native = null;
            if ($reason === null) {
                try {
                    $native = Money::fromMinor($currency, $row->minor);
                } catch (InvalidArgumentException) {
                    $reason = 'out_of_range';
                }
            }
            $rate = $row->rate_id === null ? null : ['id' => $row->rate_id, 'value' => $row->rate_value, 'source' => $row->rate_source,
                'base' => $currency->value, 'quote' => $base->value, 'observed_at' => LedgerInput::utc($row->observed_at),
                'effective_at' => LedgerInput::utc($row->effective_at), 'series_id' => $row->series_id, 'version' => (string) $row->version];
            $converted = null;
            $state = $native === null ? 'unavailable' : 'missing';
            if ($native !== null && $currency === $base) {
                $converted = $native;
                $state = 'complete';
            } elseif ($native !== null && $rate !== null) {
                $age = max((new DateTimeImmutable($cutoff))->getTimestamp() - (new DateTimeImmutable($row->observed_at))->getTimestamp(), (new DateTimeImmutable($cutoff))->getTimestamp() - (new DateTimeImmutable($row->effective_at))->getTimestamp());
                if ($age > 604800) {
                    $state = 'stale';
                } else {
                    try {
                        $converted = FxMath::convert($native, FxMath::rate($row->rate_value), $base);
                        $state = 'complete';
                    } catch (InvalidArgumentException) {
                        $state = 'unavailable';
                        $reason = 'converted_out_of_range';
                    }
                }
            }
            if ($converted !== null) {
                $subtotal = bcadd($subtotal, $converted->minor, 0);
                $covered++;
            }
            $components[] = ['account_id' => $row->account_id, 'name' => $row->name, 'type' => $row->type, 'currency' => $currency->value,
                'archived' => $row->archived_at !== null, 'native' => $native?->jsonSerialize(), 'native_reason' => $reason, 'state' => $state,
                'converted' => $converted?->jsonSerialize(), 'conversion' => $currency === $base ? 'same_currency' : 'direct_reporting_rate', 'rate' => $rate,
                'opening' => $row->intent_id === null ? null : ['intent_id' => $row->intent_id, 'minor' => $row->opening_minor, 'journal_id' => $row->opening_journal_id,
                    'effective_date' => $row->opening_effective_date, 'state' => $row->opening_state],
                'journals' => json_decode($row->journals, true, flags: JSON_THROW_ON_ERROR)];
        }
        $aggregate = null;
        try {
            $aggregate = Money::fromMinor($base, $subtotal)->jsonSerialize();
        } catch (InvalidArgumentException) {
            // Exact subtotal is retained separately, but not reported as valid Money.
        }
        $complete = $covered === count($components) && $aggregate !== null;
        $states = array_column($components, 'state');
        $state = $complete ? 'complete' : ($covered > 0 ? 'partial' : (in_array('unavailable', $states, true) ? 'unavailable' : (count(array_unique($states)) === 1 && ($states[0] ?? '') === 'stale' ? 'stale' : 'missing')));
        if ($aggregate === null) {
            $state = 'unavailable';
        }

        return ['policy' => self::POLICY, 'base_currency' => $base->value, 'cutoff' => $cutoff, 'captured_at' => $rows[0]->captured_at ?? now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'state' => $state, 'total' => $complete ? $aggregate : null, 'covered_subtotal' => $covered > 0 || $components === [] ? $aggregate : null,
            'subtotal_minor_exact' => $subtotal, 'covered_accounts' => $covered, 'components' => $components];
    }

    /** @param array<string, mixed> $evidence */
    public static function fingerprint(array $evidence): string
    {
        unset($evidence['captured_at']);
        foreach ($evidence['components'] as &$component) {
            unset($component['name'], $component['archived']);
        }
        unset($component);

        return LedgerInput::hash($evidence);
    }

    public function save(User $owner, string $key, Currency $base, string $cutoff): stdClass
    {
        return DB::transaction(function () use ($owner, $key, $base, $cutoff): stdClass {
            LedgerInput::lock($owner);
            $payload = ['base_currency' => $base->value, 'cutoff' => LedgerInput::instant($cutoff, 'cutoff')];
            if ($cached = LedgerInput::cached('valuation_reports', $owner, $key, $payload)) {
                return $cached;
            }
            $evidence = $this->capture($owner, $base, $cutoff);
            $id = DB::table('valuation_reports')->insertGetId(['owner_id' => $owner->id, 'reference' => (string) Str::ulid(), 'idempotency_key' => $key,
                'payload_hash' => LedgerInput::hash($payload), 'base_currency' => $base->value, 'cutoff' => $cutoff,
                'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'evidence_hash' => self::fingerprint($evidence)]);

            return LedgerInput::owned('valuation_reports', $owner, (string) $id);
        });
    }
}
