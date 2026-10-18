<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\BalanceSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\ExactDecimal;
use App\Domain\Shared\Money\Money;
use InvalidArgumentException;
use ValueError;

test('QAR decimal addition is exact and large integer JSON round trips as strings', function () {
    $sum = Money::fromDecimal(Currency::QAR, '0.10')->plus(Money::fromDecimal(Currency::QAR, '0.20'));
    expect($sum->minor)->toBe('30')->and($sum->amount())->toBe('0.30');
    $large = Money::fromMinor(Currency::QAR, '9007199254740993');
    $json = json_decode(json_encode($large, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($json)->toBe(['currency' => 'QAR', 'minor' => '9007199254740993', 'amount' => '90071992547409.93']);
    expect(Money::fromDecimal(Currency::QAR, $json['amount'])->minor)->toBe($large->minor);
});

test('money accepts both signed BIGINT limits and rejects arithmetic overflow', function () {
    $minimum = Money::fromMinor(Currency::QAR, Money::MIN_MINOR);
    $maximum = Money::fromMinor(Currency::QAR, Money::MAX_MINOR);
    expect($minimum->amount())->toBe('-92233720368547758.08');
    expect($maximum->amount())->toBe('92233720368547758.07');
    expect($maximum->plus($minimum)->minor)->toBe('-1');
    expect(fn () => $maximum->plus(Money::fromMinor(Currency::QAR, '1')))->toThrow(InvalidArgumentException::class);
    expect(fn () => $minimum->minus(Money::fromMinor(Currency::QAR, '1')))->toThrow(InvalidArgumentException::class);
    expect(fn () => $minimum->negated())->toThrow(InvalidArgumentException::class);
});

test('money rejects floats integers exponent notation separators and excessive scale', function (mixed $input) {
    expect(fn () => Money::fromDecimal(Currency::QAR, $input))->toThrow(InvalidArgumentException::class);
})->with([0.1, 1, true, [['0.10']], '1e2', '1,000.00', '+1.00', '01.00', '0.001', '1.000', 'NaN', 'Infinity', '-92233720368547758.09', '92233720368547758.08']);

test('minor units require bounded exact integer strings', function (mixed $input) {
    expect(fn () => Money::fromMinor(Currency::QAR, $input))->toThrow(InvalidArgumentException::class);
})->with([1, 1.0, '0.1', '01', '1e2', '9223372036854775808', '-9223372036854775809']);

test('currency exponents and strict cash input are explicit', function () {
    expect(Money::fromDecimal(Currency::JPY, '123')->amount())->toBe('123');
    expect(Money::fromDecimal(Currency::KWD, '0.001')->minor)->toBe('1');
    expect(fn () => Money::fromDecimal(Currency::JPY, '1.0'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::fromDecimal(Currency::KWD, '0.0001'))->toThrow(InvalidArgumentException::class);
    expect(Money::fromDecimal(Currency::QAR, '-0.00')->minor)->toBe('0');
});

test('mixed currencies and unsupported codes never imply conversion', function () {
    $qar = Money::fromMinor(Currency::QAR, '10');
    $ngn = Money::fromMinor(Currency::NGN, '10');
    expect(fn () => $qar->plus($ngn))->toThrow(InvalidArgumentException::class);
    expect(fn () => $qar->minus($ngn))->toThrow(InvalidArgumentException::class);
    expect(fn () => Currency::from('XXX'))->toThrow(ValueError::class);
    expect(fn () => Currency::from('qar'))->toThrow(ValueError::class);
});

test('only explicit settlement rounds half up including negative ties', function (string $input, string $expected, Currency $currency) {
    expect(Money::settle($currency, ExactDecimal::fromString($input))->amount())->toBe($expected);
})->with([
    ['1.005', '1.01', Currency::QAR], ['-1.005', '-1.01', Currency::QAR],
    ['0.0049', '0.00', Currency::QAR], ['-0.0049', '0.00', Currency::QAR],
    ['0.005', '0.01', Currency::QAR], ['-0.005', '-0.01', Currency::QAR],
    ['1.5', '2', Currency::JPY], ['-1.5', '-2', Currency::JPY],
    ['1.0005', '1.001', Currency::KWD], ['-1.0005', '-1.001', Currency::KWD],
]);

test('exact quantities prices and rate representation obey NUMERIC30 12 without truncation', function () {
    $maximum = '999999999999999999.999999999999';
    expect(ExactDecimal::fromString($maximum)->value)->toBe($maximum);
    expect(ExactDecimal::fromString('0.1')->plus(ExactDecimal::fromString('0.2'))->value)->toBe('0.3');
    expect(ExactDecimal::fromString('1.000000000001')->multipliedBy(ExactDecimal::fromString('2'))->value)->toBe('2.000000000002');
    expect(json_encode(ExactDecimal::fromString('9007199254740993.123456789012')))->toBe('"9007199254740993.123456789012"');
    expect(fn () => ExactDecimal::fromString($maximum)->plus(ExactDecimal::fromString('0.000000000001')))->toThrow(InvalidArgumentException::class);
    expect(fn () => ExactDecimal::fromString('0.000000000001')->multipliedBy(ExactDecimal::fromString('0.1')))->toThrow(InvalidArgumentException::class);
});

test('decimal representations reject overflow excessive scale and numeric input', function (mixed $input) {
    expect(fn () => ExactDecimal::fromString($input))->toThrow(InvalidArgumentException::class);
})->with([0.1, 1, '1000000000000000000', '0.1234567890123', '1.0000000000000', '1e-12', '01.5']);

test('settlement checks the final rounded signed range', function () {
    expect(Money::settle(Currency::QAR, ExactDecimal::fromString('92233720368547758.074'))->minor)->toBe(Money::MAX_MINOR);
    expect(Money::settle(Currency::QAR, ExactDecimal::fromString('-92233720368547758.084'))->minor)->toBe(Money::MIN_MINOR);
    expect(fn () => Money::settle(Currency::QAR, ExactDecimal::fromString('92233720368547758.075')))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::settle(Currency::QAR, ExactDecimal::fromString('-92233720368547758.085')))->toThrow(InvalidArgumentException::class);
});

test('derived sum rejects final overflow and is independent of cancellation order', function () {
    $max = Money::fromMinor(Currency::QAR, Money::MAX_MINOR);
    $min = Money::fromMinor(Currency::QAR, Money::MIN_MINOR);
    $one = Money::fromMinor(Currency::QAR, '1');
    $minusOne = Money::fromMinor(Currency::QAR, '-1');
    foreach ([[$max, $one, $minusOne], [$one, $max, $minusOne], [$minusOne, $one, $max]] as $movements) {
        expect(BalanceSnapshot::derive(Currency::QAR, $movements)->money?->minor)->toBe(Money::MAX_MINOR);
    }
    expect(fn () => BalanceSnapshot::derive(Currency::QAR, [$max, $one]))->toThrow(InvalidArgumentException::class);
    expect(fn () => BalanceSnapshot::derive(Currency::QAR, [$min, $minusOne]))->toThrow(InvalidArgumentException::class);
});

test('derived balances use signed movements not absolute liability values and exclude clearing', function () {
    $owed = BalanceSnapshot::derive(Currency::QAR, [Money::fromMinor(Currency::QAR, '-10000'), Money::fromMinor(Currency::QAR, '3000')]);
    expect($owed->money?->amount())->toBe('-70.00');
    expect($owed->netWorthContribution(AccountType::Liability)?->minor)->toBe('-7000');
    $credit = BalanceSnapshot::derive(Currency::QAR, [Money::fromMinor(Currency::QAR, '-10000'), Money::fromMinor(Currency::QAR, '11000')]);
    expect($credit->netWorthContribution(AccountType::Liability)?->amount())->toBe('10.00');
    expect($credit->netWorthContribution(AccountType::InternalClearing))->toBeNull();
    expect($owed->netWorthContribution(AccountType::Bank)?->minor)->toBe('-7000');
    expect(BalanceSnapshot::unavailable(Currency::QAR)->money)->toBeNull();
    expect(fn () => BalanceSnapshot::derive(Currency::QAR, [Money::fromMinor(Currency::NGN, '1')]))->toThrow(InvalidArgumentException::class);
    $cancelling = BalanceSnapshot::derive(Currency::QAR, [Money::fromMinor(Currency::QAR, Money::MAX_MINOR), Money::fromMinor(Currency::QAR, '1'), Money::fromMinor(Currency::QAR, '-1')]);
    expect($cancelling->money?->minor)->toBe(Money::MAX_MINOR);
});
