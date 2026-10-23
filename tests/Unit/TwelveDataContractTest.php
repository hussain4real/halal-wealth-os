<?php

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketSample;
use App\Domain\Integrations\ProviderFailure;
use App\Domain\Integrations\TwelveData;

function syntheticDailyQuote(array $meta = [], array $bar = []): string
{
    return json_encode(['meta' => [...['symbol' => 'SYNTH', 'currency' => 'USD', 'mic_code' => 'XNAS', 'exchange_timezone' => 'America/New_York', 'exchange' => 'NASDAQ', 'type' => 'Common Stock', 'interval' => '1day'], ...$meta], 'values' => [[...['datetime' => '2026-10-20', 'close' => '12.123456789012'], ...$bar]], 'status' => 'ok'], JSON_THROW_ON_ERROR);
}

test('Twelve Data daily raw quote metadata and date precision remain attributable', function () {
    $feed = MarketFeed::price('SYNTH', 'USD', 'XNAS', 'America/New_York');
    $v = TwelveData::parse($feed, syntheticDailyQuote(), '2026-10-21T12:00:00Z');
    expect($v->value)->toBe('12.123456789012')->and($v->time->sourceInstant)->toBeNull()
        ->and($v->time->eligibleAt)->toBe('2026-10-21T04:00:00Z')->and(MarketSample::from($v->data())->data())->toBe($v->data())
        ->and(MarketFeed::from($feed->data())->key())->toBe($feed->key());
    expect(TwelveData::parse($feed, syntheticDailyQuote(), '2026-10-22T12:00:00Z')->key())->toBe($v->key());
});

test('Twelve Data rejects wrong instrument currency and unresolved raw payloads', function (array $meta, array $bar) {
    expect(fn () => TwelveData::parse(MarketFeed::price('SYNTH', 'USD', 'XNAS', 'America/New_York'), syntheticDailyQuote($meta, $bar), '2026-10-21T12:00:00Z'))->toThrow(ProviderFailure::class);
})->with([[['currency' => 'EUR'], []], [['symbol' => 'OTHER'], []], [['mic_code' => 'XNYS'], []], [['exchange_timezone' => 'UTC'], []], [['interval' => '1min'], []], [['type' => 'Crypto'], []], [['exchange' => ''], []], [[], ['datetime' => '2026-10-22']], [[], ['close' => '1e3']], [[], ['close' => '-1']], [[], ['close' => '1000000000000000000']], [[], ['close' => '1.0000000000001']]]);

test('Twelve Data FX numeric wire rate stays exact through retained replay', function () {
    $feed = MarketFeed::fx('USD', 'QAR');
    $v = TwelveData::parse($feed, '{"symbol":"USD/QAR","rate":3.640000000001,"timestamp":1602714051}', '2020-10-15T12:00:00Z');
    expect($v->value)->toBe('3.640000000001')->and($v->time->sourceInstant)->toBe('2020-10-14T22:20:51Z')
        ->and(MarketSample::from($v->data())->data())->toBe($v->data());
});

test('Twelve Data FX rejects malformed or unavailable observations safely', function (string $body) {
    expect(fn () => TwelveData::parse(MarketFeed::fx('USD', 'QAR'), $body, '2026-10-21T12:00:00Z'))->toThrow(ProviderFailure::class);
})->with(['{"symbol":"USD/EUR","rate":3,"timestamp":1602714051}', '{"symbol":"USD/QAR","rate":0,"timestamp":1602714051}', '{"symbol":"USD/QAR","rate":1e3,"timestamp":1602714051}', '{"symbol":"USD/QAR","rate":1,"timestamp":999999999999}', '{"symbol":"USD/QAR","rate":1}', '{"status":"error","code":429,"message":"secret upstream message"}', '{"status":"error","code":400}', '{}']);

test('provider identities refuse unsupported pair and instrument guesses', function () {
    foreach ([['USD', 'USD'], ['BTC', 'USD'], ['usd', 'QAR']] as [$b, $q]) {
        expect(fn () => MarketFeed::fx($b, $q))->toThrow(ProviderFailure::class);
    }
    expect(fn () => MarketFeed::price('synth', 'USD', 'XNAS', 'America/New_York'))->toThrow(ProviderFailure::class)
        ->and(fn () => MarketFeed::price('SYNTH', 'USD', 'UNKNOWN', 'UTC'))->toThrow(ProviderFailure::class)
        ->and(fn () => MarketFeed::from(['kind' => []]))->toThrow(ProviderFailure::class);
});
