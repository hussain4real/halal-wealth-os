<?php

use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\ValuationMath;
use App\Domain\Shared\Money\Currency;

test('signed exact percentage and allocation preserve residue without float arithmetic', function () {
    expect(ValuationMath::ratio('-1', '3'))->toBe(['numerator' => '-1', 'denominator' => '3', 'percent' => '-33.3333'])
        ->and(ValuationMath::ratio('1', '0'))->toBeNull()->and(ValuationMath::ratio('1', '-1'))->toBeNull()
        ->and(ValuationMath::allocate('7', '1', '2'))->toBe('4')->and(ValuationMath::allocate('3', '1', '1'))->toBe('3');
    expect(ValuationMath::ratio('9223372036854775807', '9223372036854775807')['percent'])->toBe('100.0000');
});
test('valuation settles full quantity price precision for zero two and three exponent currencies', function () {
    expect(PortfolioInput::product(Currency::JPY, '0.5', '3')->minor)->toBe('2')
        ->and(PortfolioInput::product(Currency::QAR, '0.000000000001', '5000000000')->minor)->toBe('1')
        ->and(PortfolioInput::product(Currency::KWD, '0.5', '0.001')->minor)->toBe('1');
    expect(ValuationMath::money(Currency::JPY, '9223372036854775808'))->toBeNull();
});
