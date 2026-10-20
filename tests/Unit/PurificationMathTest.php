<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Shariah\PurificationMath;

test('purification settles full exact percentage once in native minor units', function (Currency $currency, string $base, string $rate, string $expected) {
    expect(PurificationMath::percentage($currency, $base, $rate)->minor)->toBe($expected);
})->with([
    [Currency::NGN, '10000', '2', '200'], [Currency::NGN, '9400', '2', '188'],
    [Currency::NGN, '1', '50', '1'], [Currency::NGN, '1', '49.999999999999', '0'],
    [Currency::JPY, '100', '2', '2'], [Currency::KWD, '100000', '2', '2000'],
    [Currency::NGN, '9223372036854775807', '0.000000000001', '92234'],
    [Currency::NGN, '9223372036854775807', '100', '9223372036854775807'],
    [Currency::NGN, '10000', '0', '0'], [Currency::NGN, '0', '999999999999999999.999999999999', '0'],
]);
test('purification rejects invalid precision signs notation types and settled overflow', function (mixed $base, mixed $rate) {
    expect(fn () => PurificationMath::percentage(Currency::NGN, $base, $rate))->toThrow(InvalidArgumentException::class);
})->with([['-1', '2'], ['1', '-1'], ['1', '0.0000000000001'], ['1', '1e2'], ['9223372036854775807', '100.000000000001']]);
