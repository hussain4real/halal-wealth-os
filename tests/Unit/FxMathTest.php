<?php

use App\Domain\Ledger\FxMath;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;

test('FX exact intermediates support large JPY and settle only final target cash', function (Currency $source, string $minor, string $rate, Currency $target, string $expected) {
    $result = FxMath::convert(Money::fromMinor($source, $minor), FxMath::rate($rate), $target);
    expect($result->minor)->toBe($expected)->and($result->currency)->toBe($target);
})->with([
    [Currency::JPY, Money::MAX_MINOR, '0.01', Currency::USD, Money::MAX_MINOR],
    [Currency::QAR, '1', '405.5', Currency::NGN, '406'],
    [Currency::QAR, '-1', '405.5', Currency::NGN, '-406'],
    [Currency::KWD, '1', '0.000000000001', Currency::USD, '0'],
    [Currency::KWD, '15', '1', Currency::USD, '2'],
    [Currency::KWD, '-15', '1', Currency::USD, '-2'],
    [Currency::USD, '150', '1', Currency::JPY, '2'],
]);

test('FX rates reject invalid positivity precision and numeric coercion', function (mixed $value) {
    expect(fn () => FxMath::rate($value))->toThrow(InvalidArgumentException::class);
})->with(['0', '-1', '1e2', '1.0000000000000', '1000000000000000000', 1, 0.5, null]);

test('FX final target overflow rejects without weakening valid native cash range', function () {
    expect(fn () => FxMath::convert(Money::fromMinor(Currency::JPY, Money::MAX_MINOR), FxMath::rate('1'), Currency::USD))->toThrow(InvalidArgumentException::class);
    $ratio = FxMath::implied(Money::fromMinor(Currency::QAR, '3'), Money::fromMinor(Currency::NGN, '1'));
    expect($ratio['numerator'])->toBe('100')->and($ratio['denominator'])->toBe('300')->and($ratio['rounded'])->toBeTrue();
});
