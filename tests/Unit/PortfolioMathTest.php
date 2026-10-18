<?php

use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Shared\Money\Currency;

test('full 24-place position products settle only at the native cash boundary', function ($currency, $quantity, $price, $minor) {
    expect(PortfolioInput::product(Currency::from($currency), $quantity, $price)->minor)->toBe($minor);
})->with([
    ['QAR', '1.234567890123', '2.000000000001', '247'],
    ['QAR', '0.000000000001', '4999999999.999999999999', '0'],
    ['QAR', '0.000000000001', '5000000000.000000000001', '1'],
    ['JPY', '1', '0.5', '1'], ['KWD', '0.1', '0.005', '1'],
    ['QAR', '9007199254740993.123456789012', '0', '0'],
]);
test('unrepresentable position settlement is explicit rather than truncated', function () {
    expect(fn () => PortfolioInput::product(Currency::QAR, '999999999999999999', '999999999999999999'))->toThrow(InvalidArgumentException::class);
});
