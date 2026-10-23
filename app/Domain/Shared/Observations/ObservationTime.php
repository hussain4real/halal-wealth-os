<?php

declare(strict_types=1);

namespace App\Domain\Shared\Observations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Source date/instant and the independently observed application availability. */
final readonly class ObservationTime
{
    private function __construct(
        public ?string $sourceDate,
        public ?string $zone,
        public ?string $sourceInstant,
        public string $receivedAt,
        public string $eligibleAt,
        public string $ageFrom,
    ) {}

    public static function daily(string $date, string $zone, string $received): self
    {
        $known = self::instant($received);
        if (! in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Unknown source calendar.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone($zone));
        if ($start === false || $start->format('Y-m-d') !== $date || $date < '0001-01-01' || $date > '9998-12-31') {
            throw new InvalidArgumentException('Invalid source date.');
        }
        $nextDate = (new DateTimeImmutable($date.'T00:00:00Z'))->modify('+1 day')->format('Y-m-d');
        $end = new DateTimeImmutable($nextDate.'T00:00:00', new DateTimeZone($zone));
        if ($end > $known) {
            throw new InvalidArgumentException('Source day is future or incomplete.');
        }

        return new self($date, $zone, null, self::utc($known), self::utc($end), self::utc($start));
    }

    public static function timestamp(string $timestamp, string $received): self
    {
        $known = self::instant($received);
        if (! preg_match('/^[0-9]{1,12}$/D', $timestamp)) {
            throw new InvalidArgumentException('Invalid source timestamp.');
        }
        $source = new DateTimeImmutable('@'.$timestamp);
        if ($source > $known || $source->format('Y') < '0001' || $source->format('Y') > '9998') {
            throw new InvalidArgumentException('Source timestamp is future or out of range.');
        }

        return new self(null, null, self::utc($source), self::utc($known), self::utc($source), self::utc($source));
    }

    public function eligible(string $cutoff): bool
    {
        $at = self::instant($cutoff);

        return self::instant($this->receivedAt) <= $at && self::instant($this->eligibleAt) <= $at;
    }

    public function stale(string $cutoff): bool
    {
        return self::instant($cutoff)->getTimestamp() - self::instant($this->ageFrom)->getTimestamp() > 604800;
    }

    /** @return array<string,string|null> */
    public function columns(): array
    {
        return ['observed_at' => $this->sourceInstant, 'effective_at' => $this->sourceInstant,
            'source_date' => $this->sourceDate, 'source_timezone' => $this->zone, 'received_at' => $this->receivedAt,
            'eligible_at' => $this->eligibleAt, 'age_from' => $this->ageFrom,
            'time_policy' => $this->sourceDate === null ? 'provider-instant-v1' : 'daily-closed-v1'];
    }

    private static function instant(string $value): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($instant === false || $instant->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('Expected an exact UTC instant.');
        }

        return $instant;
    }

    private static function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
