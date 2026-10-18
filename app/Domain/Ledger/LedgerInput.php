<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Shared\Money\Money;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class LedgerInput
{
    public static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function text(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 180) {
            self::fail($field, 'Enter between 1 and 180 characters.');
        }

        return trim($value);
    }

    public static function key(string $key): string
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $key)) {
            self::fail('idempotency_key', 'Invalid request identity. Check saved history before starting again.');
        }

        return $key;
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function date(mixed $value, string $field = 'effective_date'): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || ! $date || $date->format('Y-m-d') !== $value || $value < '0001-01-01' || $value > now('UTC')->toDateString()) {
            self::fail($field, 'Choose a valid UTC business date on or before today.');
        }

        return $value;
    }

    public static function instant(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) {
            self::fail($field, 'Use a UTC timestamp with seconds, for example YYYY-MM-DDTHH:MM:SSZ.');
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (! $time || $time->format('Y-m-d\TH:i:s\Z') !== $value || $value < '0001-01-01T00:00:00Z' || $time->getTimestamp() > now('UTC')->getTimestamp()) {
            self::fail($field, 'Choose a valid UTC time on or before now.');
        }

        return $value;
    }

    public static function lock(User $owner): void
    {
        User::query()->whereKey($owner->id)->firstOrFail();
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['hwo:posting:owner:'.$owner->id]);
    }

    public static function utc(string $value): string
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function identifier(mixed $id): string
    {
        abort_unless(is_string($id) && preg_match('/^[1-9][0-9]{0,18}$/D', $id) && (strlen($id) < 19 || strcmp($id, Money::MAX_MINOR) <= 0), 404);

        return $id;
    }

    public static function owned(string $table, User $owner, string $id, bool $lock = false): stdClass
    {
        self::identifier($id);
        $query = DB::table($table)->where('owner_id', $owner->id)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        abort_unless($row instanceof stdClass, 404);

        return $row;
    }

    /** @param array<string, mixed> $payload */
    public static function cached(string $table, User $owner, string $key, array $payload): ?stdClass
    {
        self::key($key);
        $row = DB::table($table)->where('owner_id', $owner->id)->where('idempotency_key', $key)->first();
        if ($row !== null && $row->payload_hash !== self::hash($payload)) {
            self::fail('idempotency_key', 'This request identity already has different details. Check saved history.');
        }

        return $row;
    }
}
