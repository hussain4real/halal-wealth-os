<?php

use App\Domain\Integrations\JsonLexemes;
use App\Domain\Integrations\ProviderFailure;
use App\Domain\Shared\Observations\ObservationTime;

test('provider decimals retain exact wire lexemes including signed integer bounds', function () {
    $v = JsonLexemes::decode(<<<'JSON'
{"rate":0.123456789012,"timestamp":1602714051,"nested":[true,false,null,"a\"b",{"amount":9223372036854775807}]}
JSON);
    expect($v['rate'])->toBe('0.123456789012')->and($v['timestamp'])->toBe('1602714051')
        ->and($v['nested'])->toBe([true, false, null, 'a"b', ['amount' => '9223372036854775807']]);
    expect(JsonLexemes::decode('{"empty":{},"list":[],"scientific":1e3}'))->toBe(['empty' => [], 'list' => [], 'scientific' => '1e3']);
});

test('ambiguous duplicate json keys are rejected even with escaped spelling', function (string $body) {
    expect(fn () => JsonLexemes::decode($body))->toThrow(ProviderFailure::class);
})->with(['{"rate":1,"rate":2}', '{"rate":1,"r\\u0061te":2}', '{"meta":{"currency":"USD","currency":"EUR"}}', '{"broken":}', 'true', str_repeat(' ', 65537), '['.str_repeat('[],', 2000).'[]]', '{"a":'.str_repeat('[', 20).'0'.str_repeat(']', 20).'}']);

test('daily observation precision preserves its calendar and original known at cutoff', function () {
    $v = ObservationTime::daily('2026-03-08', 'America/New_York', '2026-03-10T12:00:00Z');
    expect($v->columns())->toMatchArray(['observed_at' => null, 'effective_at' => null, 'source_date' => '2026-03-08', 'source_timezone' => 'America/New_York', 'eligible_at' => '2026-03-09T04:00:00Z', 'age_from' => '2026-03-08T05:00:00Z'])
        ->and($v->eligible('2026-03-09T12:00:00Z'))->toBeFalse()->and($v->eligible('2026-03-10T12:00:00Z'))->toBeTrue()
        ->and($v->stale('2026-03-15T05:00:00Z'))->toBeFalse()->and($v->stale('2026-03-15T05:00:01Z'))->toBeTrue();
});

test('a dated feed cannot fabricate an instant from future or incomplete dates', function (string $date, string $zone, string $received) {
    expect(fn () => ObservationTime::daily($date, $zone, $received))->toThrow(InvalidArgumentException::class);
})->with([['2026-02-30', 'UTC', '2026-03-05T00:00:00Z'], ['2026-03-08', 'America/New_York', '2026-03-09T03:59:59Z'], ['2026-03-11', 'UTC', '2026-03-10T00:00:00Z'], ['2026-03-08', 'EST invented', '2026-03-10T00:00:00Z'], ['2026-03-08', 'UTC', 'not-an-instant']]);

test('timestamp feeds preserve real source instant and independently received availability', function () {
    $v = ObservationTime::timestamp('1602714051', '2020-10-16T00:00:00Z');
    expect($v->sourceInstant)->toBe('2020-10-14T22:20:51Z')->and($v->columns()['time_policy'])->toBe('provider-instant-v1')
        ->and($v->eligible('2020-10-15T12:00:00Z'))->toBeFalse()->and($v->eligible('2020-10-16T00:00:00Z'))->toBeTrue();
    expect(fn () => ObservationTime::timestamp('999999999999', '2026-01-01T00:00:00Z'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ObservationTime::timestamp('1e9', '2026-01-01T00:00:00Z'))->toThrow(InvalidArgumentException::class);
});

test('a skipped source midnight does not move the next calendar boundary forward', function () {
    $v = ObservationTime::daily('2018-11-04', 'America/Sao_Paulo', '2018-11-05T02:30:00Z');
    expect($v->eligibleAt)->toBe('2018-11-05T02:00:00Z')->and($v->ageFrom)->toBe('2018-11-04T03:00:00Z');
});
