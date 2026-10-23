<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Shared\Observations\ObservationTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class TwelveData implements FxProvider, PriceProvider
{
    public function price(MarketFeed $feed): MarketSample
    {
        $end = now($feed->identity['exchange_timezone'])->subDay()->toDateString().' 23:59:59';

        return self::parse($feed, $this->request('time_series', [
            'symbol' => $feed->identity['symbol'], 'mic_code' => $feed->identity['mic_code'],
            'interval' => '1day', 'adjust' => 'none', 'outputsize' => '1', 'end_date' => $end,
        ]), now('UTC')->format('Y-m-d\TH:i:s\Z'));
    }

    public function fx(MarketFeed $feed): MarketSample
    {
        return self::parse($feed, $this->request('exchange_rate', ['symbol' => $feed->identity['symbol'], 'timezone' => 'UTC']), now('UTC')->format('Y-m-d\TH:i:s\Z'));
    }

    /** @param array<string,string> $query */
    private function request(string $endpoint, array $query): string
    {
        if (! self::mockEnabled()) {
            throw new ProviderFailure('disabled');
        }
        // Fake transport must be explicit; no testing configuration may send an unmatched real request.
        Http::preventStrayRequests();
        try {
            $response = Http::connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false])
                ->get('https://api.twelvedata.com/'.$endpoint, [...$query, 'apikey' => 'synthetic-mock-only']);
        } catch (ConnectionException) {
            throw new ProviderFailure('timeout', true, 60);
        }
        if ($response->status() === 429) {
            $retry = $response->header('Retry-After');
            $delay = 60;
            if (ctype_digit($retry)) {
                if (strlen($retry) > 6 || (int) $retry > 604800) {
                    throw new ProviderFailure('backoff_exceeds_policy');
                }
                $delay = max(60, (int) $retry);
            } else {
                $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $retry, new DateTimeZone('UTC'));
                if ($date !== false && $date->format(DATE_RFC7231) === $retry) {
                    $delay = max(60, $date->getTimestamp() - now('UTC')->getTimestamp());
                    if ($delay > 604800) {
                        throw new ProviderFailure('backoff_exceeds_policy');
                    }
                }
            }
            throw new ProviderFailure('rate_limited', true, $delay);
        }
        if ($response->serverError()) {
            throw new ProviderFailure('upstream_unavailable', true, 60);
        }
        if (! $response->successful()) {
            throw new ProviderFailure('access_or_http_error');
        }

        return $response->body();
    }

    public static function mockEnabled(): bool
    {
        return app()->environment('testing') && config('market_data.mode') === 'mock';
    }

    public static function parse(MarketFeed $feed, string $body, string $received): MarketSample
    {
        $data = JsonLexemes::decode($body);
        if (($data['status'] ?? null) === 'error') {
            throw new ProviderFailure(($data['code'] ?? null) === '429' ? 'rate_limited' : 'provider_rejection', ($data['code'] ?? null) === '429', 60);
        }
        try {
            if ($feed->kind === 'price') {
                $meta = $data['meta'] ?? [];
                foreach ($feed->identity as $field => $expected) {
                    if (($meta[$field] ?? null) !== $expected) {
                        throw new ProviderFailure($field === 'currency' ? 'wrong_currency' : 'instrument_identity');
                    }
                }
                if (($meta['interval'] ?? null) !== '1day' || ! in_array($meta['type'] ?? null, ['Common Stock', 'ETF'], true)
                    || ! is_string($meta['exchange'] ?? null) || $meta['exchange'] === ''
                    || ! is_array($data['values'] ?? null) || count($data['values']) !== 1 || ! is_string($data['values'][0]['datetime'] ?? null)) {
                    throw new ProviderFailure('invalid_payload');
                }
                $time = ObservationTime::daily($data['values'][0]['datetime'], $meta['exchange_timezone'], $received);
                $value = $data['values'][0]['close'] ?? null;
            } else {
                if (($data['symbol'] ?? null) !== $feed->identity['symbol']) {
                    throw new ProviderFailure('wrong_currency');
                }
                $time = ObservationTime::timestamp(is_string($data['timestamp'] ?? null) ? $data['timestamp'] : '', $received);
                $value = $data['rate'] ?? null;
            }
        } catch (InvalidArgumentException) {
            throw new ProviderFailure('source_time');
        }

        return MarketSample::make($feed, $value, $time);
    }
}
