<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Shared\Money\ExactDecimal;
use App\Domain\Shared\Observations\ObservationTime;
use InvalidArgumentException;

final readonly class MarketSample
{
    private function __construct(public MarketFeed $feed, public string $value, public ObservationTime $time) {}

    public static function make(MarketFeed $feed, mixed $value, ObservationTime $time): self
    {
        try {
            $decimal = ExactDecimal::fromString($value)->value;
            if (bccomp($decimal, '0', 12) < 0 || ($feed->kind === 'fx' && bccomp($decimal, '0', 12) === 0)) {
                throw new InvalidArgumentException('Invalid quote sign.');
            }
        } catch (InvalidArgumentException) {
            throw new ProviderFailure('invalid_number');
        }

        return new self($feed, $decimal, $time);
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        return ['feed' => $this->feed->data(), 'value' => $this->value, 'time' => $this->time->columns()];
    }

    /** Stable source identity excludes retrieval time and mapping/queue identities. */
    public function key(): string
    {
        return hash('sha256', json_encode([$this->feed->data(), $this->value, $this->time->sourceDate, $this->time->zone, $this->time->sourceInstant], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $data */
    public static function from(array $data): self
    {
        $feed = MarketFeed::from($data['feed']);
        $time = $data['time'];
        $source = $time['source_date'] === null
            ? ObservationTime::timestamp((string) strtotime($time['observed_at']), $time['received_at'])
            : ObservationTime::daily($time['source_date'], $time['source_timezone'], $time['received_at']);

        return self::make($feed, $data['value'], $source);
    }
}
