<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Shared\Money\Currency;
use DateTimeZone;

final readonly class MarketFeed
{
    /** @param array<string,string> $identity */
    private function __construct(public string $kind, public array $identity) {}

    public static function price(string $symbol, string $currency, string $mic, string $zone): self
    {
        if (! preg_match('/^[A-Z0-9][A-Z0-9.\/-]{0,31}$/D', $symbol) || Currency::tryFrom($currency) === null
            || ! preg_match('/^[A-Z0-9]{4}$/D', $mic) || ! in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new ProviderFailure('instrument_identity');
        }

        return new self('price', ['symbol' => $symbol, 'currency' => $currency, 'mic_code' => $mic, 'exchange_timezone' => $zone]);
    }

    public static function fx(string $base, string $quote): self
    {
        if (Currency::tryFrom($base) === null || Currency::tryFrom($quote) === null || $base === $quote) {
            throw new ProviderFailure('unsupported_pair');
        }

        return new self('fx', ['symbol' => $base.'/'.$quote, 'base' => $base, 'quote' => $quote]);
    }

    /** @param array<string,mixed> $data */
    public static function from(array $data): self
    {
        if (! in_array($data['kind'] ?? null, ['price', 'fx'], true)) {
            throw new ProviderFailure('instrument_identity');
        }
        $strings = [];
        foreach ($data as $key => $value) {
            if (! is_string($value)) {
                throw new ProviderFailure('instrument_identity');
            }
            $strings[$key] = $value;
        }

        return ($strings['kind'] ?? '') === 'price'
            ? self::price($strings['symbol'] ?? '', $strings['currency'] ?? '', $strings['mic_code'] ?? '', $strings['exchange_timezone'] ?? '')
            : self::fx($strings['base'] ?? '', $strings['quote'] ?? '');
    }

    /** @return array<string,string> */
    public function data(): array
    {
        return ['kind' => $this->kind, ...$this->identity];
    }

    public function key(): string
    {
        return hash('sha256', json_encode($this->data(), JSON_THROW_ON_ERROR));
    }
}
