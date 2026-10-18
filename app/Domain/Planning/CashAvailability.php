<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

final class CashAvailability
{
    public const string POLICY = 'cash-availability-v1;explicit-income-date;overdue-inclusive;native-group-paired-half-up;direct-reporting-7d';

    /** @return array<string,mixed> */
    public function capture(User $owner, ?string $planAfter = null): array
    {
        return DB::transaction(function () use ($owner, $planAfter): array {
            LedgerInput::lock($owner);
            $instant = now('UTC');
            $cutoff = $instant->format('Y-m-d\TH:i:s\Z');
            $utcDate = $instant->toDateString();
            $today = BudgetCalendar::today();
            $settings = app(AvailabilitySettings::class)->latest($owner);
            $base = Currency::from($settings->currency ?? 'QAR');
            $requirements = $settings === null ? [] : json_decode($settings->requirements, true, flags: JSON_THROW_ON_ERROR);
            $overlaps = $settings === null ? [] : json_decode($settings->overlap_links, true, flags: JSON_THROW_ON_ERROR);
            $investments = $settings === null ? [] : json_decode($settings->investment_goals, true, flags: JSON_THROW_ON_ERROR);
            $sources = app(AvailabilitySources::class)->read($owner, $utcDate, $investments, array_values(array_unique(array_column($overlaps, 'reservation_id'))));
            $commitments = app(AvailabilityCommitments::class)->read($owner, $utcDate, $today, $settings->next_income_date ?? $today, $requirements, $overlaps, $sources['reservations']);
            $choices = DB::select(<<<'SQL'
SELECT root.id::text AS root_id,s.id::text AS schedule_id,s.family_reference AS family,s.name,s.currency,c.direction
FROM planning_schedules root JOIN planning_schedules s ON s.family_reference=root.family_reference AND s.owner_id=root.owner_id
JOIN planning_categories c ON c.id=s.category_id
WHERE root.owner_id=? AND root.supersedes_id IS NULL AND c.direction='expense'
AND NOT EXISTS(SELECT 1 FROM planning_schedules n JOIN planning_categories nc ON nc.id=n.category_id WHERE n.family_reference=s.family_reference AND n.owner_id=s.owner_id AND n.version>s.version AND nc.direction='expense')
AND (?::bigint IS NULL OR root.id>?::bigint) ORDER BY root.id LIMIT 21
SQL, [$owner->id, $planAfter, $planAfter]);
            $nextChoice = count($choices) > 20 ? (string) $choices[19]->root_id : null;
            $choices = array_slice($choices, 0, 20);
            $unclassified = (int) DB::selectOne(<<<'SQL'
SELECT count(DISTINCT s.family_reference) AS count FROM planning_schedules s
JOIN planning_categories c ON c.id=s.category_id WHERE s.owner_id=? AND c.direction='expense'
AND NOT EXISTS(SELECT 1 FROM jsonb_to_recordset(?::jsonb) AS r(family text) WHERE r.family=s.family_reference)
SQL, [$owner->id, json_encode($requirements, JSON_THROW_ON_ERROR)])->count;
            $missing = [];
            if (isset($commitments['reason'])) {
                $missing[] = $commitments['reason'];
            }
            if ($settings === null) {
                $missing[] = 'settings_missing';
            }
            if ($settings === null || $settings->next_income_date < $today) {
                $missing[] = 'next_income_date_needs_confirmation';
            }
            if ($unclassified > 0) {
                $missing[] = 'unclassified_required_commitments';
            }
            $rates = [];
            foreach (DB::select(<<<'SQL'
SELECT DISTINCT ON(base_currency) id::text,value::text,source,base_currency AS base,quote_currency AS quote,observed_at,effective_at,series_id,version
FROM fx_observations WHERE owner_id=? AND purpose='reporting' AND quote_currency=? AND observed_at<=?::timestamptz AND effective_at<=?::timestamptz
ORDER BY base_currency,effective_at DESC,observed_at DESC,id DESC
SQL, [$owner->id, $base->value, $cutoff, $cutoff]) as $rate) {
                $rates[$rate->base] = [...(array) $rate, 'observed_at' => LedgerInput::utc($rate->observed_at), 'effective_at' => LedgerInput::utc($rate->effective_at)];
            }
            $groups = [];
            foreach ([$sources['groups'], $sources['contributions'], $commitments['groups']] as $set) {
                foreach ($set as $group) {
                    $code = $group['currency'];
                    if (array_key_exists('minor', $group)) {
                        $group['contribution'] = $group['minor'];
                        unset($group['minor']);
                    }
                    $groups[$code] = [...($groups[$code] ?? []), ...$group];
                }
            }
            ksort($groups);
            $math = app(AvailabilityMath::class);
            $actual = $math->calculate($base, $groups, $rates, $cutoff, $settings === null ? null : (string) $settings->buffer_minor,
                $settings === null ? null : (string) $settings->investment_target_minor, $missing);
            $watermarks = DB::selectOne(<<<'SQL'
SELECT (SELECT coalesce(max(id),0)::text FROM planning_schedules WHERE owner_id=?) AS schedules,
 (SELECT coalesce(max(id),0)::text FROM planning_allocations WHERE owner_id=?) AS allocations,
 (SELECT coalesce(max(id),0)::text FROM planning_releases WHERE owner_id=?) AS releases
SQL, [$owner->id, $owner->id, $owner->id]);
            $fingerprint = LedgerInput::hash(['policy' => self::POLICY, 'today' => $today, 'utc_date' => $utcDate, 'settings' => $settings?->id,
                'groups' => $groups, 'rates' => $rates, 'actual_state' => $actual['state'], 'missing' => $actual['missing'], 'forecast_missing' => $actual['forecast_missing'],
                'source_watermark' => $sources['source_watermark'], 'goal_watermark' => $sources['goal_watermark'], 'reservation_watermark' => $sources['reservation_watermark'],
                'release_watermark' => $sources['release_watermark'], 'planning_watermarks' => (array) $watermarks]);

            return ['policy' => self::POLICY, 'cutoff' => $cutoff, 'today' => $today, 'fingerprint' => $fingerprint,
                'actual' => $actual, 'forecast' => $math->forecast($actual), 'forecast_period' => ['start' => $commitments['month_start'], 'end' => $commitments['month_end']],
                'settings' => $settings === null ? null : $this->settings($settings), 'accounts' => $sources['accounts'], 'account_count' => $sources['account_count'],
                'occurrences' => $commitments['occurrences'], 'occurrence_count' => $commitments['occurrence_count'],
                'required_next_cursor' => $nextChoice, 'unclassified_count' => $unclassified, 'plan_after' => $planAfter,
                'required_choices' => array_map(fn (stdClass $s): array => (array) $s, $choices)];
        });
    }

    /** No authoritative write, even on repeated or stale scenario requests.
     * @param array<string,mixed> $overrides
     * @return array<string,mixed> */
    public function scenario(User $owner, string $fingerprint, array $overrides): array
    {
        $snapshot = $this->capture($owner);
        abort_unless(hash_equals($snapshot['fingerprint'], $fingerprint), 409, 'The source records changed. Refresh actual now before recalculating this scenario.');

        return ['fingerprint' => $snapshot['fingerprint'], 'actual' => $snapshot['actual'], 'forecast_period' => $snapshot['forecast_period'],
            'forecast' => app(AvailabilityMath::class)->forecast($snapshot['actual'], $overrides)];
    }

    /** @return array<string,mixed> */
    private function settings(stdClass $s): array
    {
        $currency = Currency::from($s->currency);

        return ['id' => (string) $s->id, 'reference' => $s->reference, 'version' => (string) $s->version,
            'prior_id' => $s->supersedes_id === null ? '' : (string) $s->supersedes_id, 'currency' => $s->currency,
            'buffer' => Money::fromMinor($currency, (string) $s->buffer_minor)->amount(), 'next_income_date' => $s->next_income_date,
            'investment_target' => Money::fromMinor($currency, (string) $s->investment_target_minor)->amount(),
            'requirements' => json_decode($s->requirements, true, flags: JSON_THROW_ON_ERROR),
            'overlaps' => array_map(fn (array $link): array => [...$link, 'amount' => Money::fromMinor(Currency::from($link['currency']), $link['minor'])->amount()], json_decode($s->overlap_links, true, flags: JSON_THROW_ON_ERROR)),
            'investment_goals' => json_decode($s->investment_goals, true, flags: JSON_THROW_ON_ERROR), 'reason' => $s->reason];
    }
}
