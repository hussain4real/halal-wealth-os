<?php

use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\ProviderFailure;
use App\Domain\Integrations\TwelveData;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-23T12:00:00Z'));
    config(['market_data.mode' => 'mock']);
    Http::preventStrayRequests();
});

test('provider HTTP failures expose only fixed safe codes and honor bounded Retry After', function (int $status, string $header, string $code, bool $retryable, int $delay) {
    Http::fake(['api.twelvedata.com/*' => Http::response('SENSITIVE UPSTREAM BODY', $status, ['Retry-After' => $header])]);
    try {
        app(TwelveData::class)->fx(MarketFeed::fx('USD', 'QAR'));
        $this->fail('Provider failure must reject.');
    } catch (ProviderFailure $error) {
        expect($error->reason)->toBe($code)->and($error->retryable)->toBe($retryable)->and($error->retryAfter)->toBe($delay)
            ->and($error->getMessage())->not->toContain('SENSITIVE');
    }
})->with([
    [429, 'Fri, 23 Oct 2026 12:05:00 GMT', 'rate_limited', true, 300],
    [429, 'Thu, 22 Oct 2026 12:05:00 GMT', 'rate_limited', true, 60],
    [429, 'malformed', 'rate_limited', true, 60],
    [429, '999999999999999999', 'backoff_exceeds_policy', false, 0],
    [429, 'Fri, 30 Oct 2026 12:00:01 GMT', 'backoff_exceeds_policy', false, 0],
    [503, '', 'upstream_unavailable', true, 60],
    [401, '', 'access_or_http_error', false, 0],
    [302, '', 'access_or_http_error', false, 0],
]);

test('an unmatched mock request is blocked before external HTTP and disabled adapters never request', function () {
    expect(fn () => app(TwelveData::class)->fx(MarketFeed::fx('USD', 'QAR')))->toThrow(StrayRequestException::class);
    config(['market_data.mode' => 'disabled']);
    expect(fn () => app(TwelveData::class)->fx(MarketFeed::fx('USD', 'QAR')))->toThrow(ProviderFailure::class, 'disabled');
});
