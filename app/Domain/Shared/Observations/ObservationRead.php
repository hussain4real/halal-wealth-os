<?php

declare(strict_types=1);

namespace App\Domain\Shared\Observations;

use App\Domain\Ledger\LedgerInput;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use stdClass;

final class ObservationRead
{
    public static function latest(Builder $query, string $cutoff): ?stdClass
    {
        return $query->whereRaw(self::eligibilitySql('', '?'), [$cutoff, $cutoff, $cutoff, $cutoff])
            ->orderByRaw('COALESCE(effective_at,eligible_at) DESC')->orderByRaw('COALESCE(observed_at,received_at) DESC')->orderByDesc('id')->first();
    }

    /** Alias and cutoff are internal SQL identifiers/expressions, never request values.
     * @param  literal-string  $alias
     * @param  literal-string  $cutoff
     * @return literal-string
     */
    public static function eligibilitySql(string $alias, string $cutoff): string
    {
        $p = $alias === '' ? '' : $alias.'.';

        return "((({$p}source_date IS NULL AND {$p}observed_at<={$cutoff} AND {$p}effective_at<={$cutoff})"
            ." OR ({$p}source_date IS NOT NULL AND {$p}eligible_at<={$cutoff})) AND ({$p}received_at IS NULL OR {$p}received_at<={$cutoff}))";
    }

    /** @param array<string,mixed> $rate */
    public static function payloadEligible(array $rate, string $cutoff): bool
    {
        $at = new DateTimeImmutable($cutoff);
        if (isset($rate['provider_time'])) {
            return new DateTimeImmutable($rate['provider_time']['received_at']) <= $at
                && new DateTimeImmutable($rate['provider_time']['eligible_after']) <= $at;
        }

        return new DateTimeImmutable($rate['observed_at']) <= $at && new DateTimeImmutable($rate['effective_at']) <= $at;
    }

    /** @param array<string,mixed> $rate */
    public static function payloadStale(array $rate, string $cutoff): bool
    {
        $oldest = isset($rate['provider_time']) ? (new DateTimeImmutable($rate['provider_time']['age_from']))->getTimestamp()
            : min((new DateTimeImmutable($rate['observed_at']))->getTimestamp(), (new DateTimeImmutable($rate['effective_at']))->getTimestamp());

        return (new DateTimeImmutable($cutoff))->getTimestamp() - $oldest > 604800;
    }

    public static function stale(stdClass $row, string $cutoff): bool
    {
        $at = (new DateTimeImmutable($cutoff))->getTimestamp();
        $oldest = $row->age_from !== null ? (new DateTimeImmutable($row->age_from))->getTimestamp()
            : min((new DateTimeImmutable($row->effective_at))->getTimestamp(), (new DateTimeImmutable($row->observed_at))->getTimestamp());

        return $at - $oldest > 604800;
    }

    /** @return array<string,mixed> */
    public static function evidence(stdClass $row): array
    {
        $times = ['observed_at' => $row->observed_at === null ? null : LedgerInput::utc($row->observed_at),
            'effective_at' => $row->effective_at === null ? null : LedgerInput::utc($row->effective_at)];
        if ($row->time_policy !== null) {
            $times['provider_time'] = ['precision' => $row->source_date === null ? 'instant' : 'date',
                'source_date' => $row->source_date, 'source_timezone' => $row->source_timezone,
                'received_at' => LedgerInput::utc($row->received_at), 'eligible_after' => LedgerInput::utc($row->eligible_at),
                'age_from' => LedgerInput::utc($row->age_from), 'policy' => $row->time_policy];
        }

        return $times;
    }
}
