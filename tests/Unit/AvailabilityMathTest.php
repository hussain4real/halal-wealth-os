<?php

use App\Domain\Planning\AvailabilityMath;
use App\Domain\Shared\Money\Currency;

test('fully protected foreign cash cannot gain a rounding-created spendable residual', function () {
    $rate = ['id' => '1', 'base' => 'USD', 'quote' => 'QAR', 'value' => '0.5', 'observed_at' => '2026-10-18T00:00:00Z', 'effective_at' => '2026-10-18T00:00:00Z'];
    $r = (new AvailabilityMath)->calculate(Currency::QAR, ['USD' => ['cash' => '2', 'protected' => '2']], ['USD' => $rate], '2026-10-18T12:00:00Z', '0', '0', []);
    expect($r['state'])->toBe('complete')->and($r['raw']['minor'])->toBe('0')->and($r['breakdown']['cash']['minor'])->toBe('1')->and($r['breakdown']['protected']['minor'])->toBe('1');
});
test('forecast bill payment consumes explicitly overlapping protection once', function () {
    $math = new AvailabilityMath;
    $actual = $math->calculate(Currency::QAR, ['QAR' => ['cash' => '100000', 'protected' => '50000', 'monthly_commitments' => '50000', 'monthly_overlap' => '50000', 'salary_count' => 1]], [], '2026-10-18T12:00:00Z', '0', '0', []);
    $forecast = $math->forecast($actual);
    expect($forecast['cash']['minor'])->toBe('50000')->and($forecast['free']['minor'])->toBe('50000')->and($forecast['details']['remaining_protection']['minor'])->toBe('0');
});
test('investment target includes its explicitly selected existing reservations', function () {
    $math = new AvailabilityMath;
    $actual = $math->calculate(Currency::QAR, ['QAR' => ['cash' => '100000', 'protected' => '40000', 'investment_backed' => '40000', 'salary_count' => 1]], [], '2026-10-18T12:00:00Z', '0', '60000', []);
    $forecast = $math->forecast($actual);
    expect($forecast['details']['additional_investment']['minor'])->toBe('20000')->and($forecast['free']['minor'])->toBe('40000');
});

test('tiny foreign bill overlap never releases more rounded protection than exists', function () {
    $math = new AvailabilityMath;
    $rate = ['id' => '1', 'base' => 'USD', 'quote' => 'QAR', 'value' => '0.5', 'observed_at' => '2026-10-18T00:00:00Z', 'effective_at' => '2026-10-18T00:00:00Z'];
    $actual = $math->calculate(Currency::QAR, ['USD' => ['cash' => '2', 'protected' => '1', 'monthly_commitments' => '1', 'monthly_overlap' => '1', 'salary_count' => 1]], ['USD' => $rate], '2026-10-18T12:00:00Z', '0', '0', []);
    $forecast = $math->forecast($actual);
    expect($actual['breakdown']['protected']['minor'])->toBe('0')->and($forecast['state'])->toBe('complete')
        ->and($forecast['details']['remaining_protection']['minor'])->toBe('0')->and($forecast['details']['bill_overlap']['minor'])->toBe('0')
        ->and($forecast['details']['rounding_overlap_not_applied']['minor'])->toBe('1')->and($forecast['free']['minor'])->toBe('0');
});

test('tiny foreign bill overlap cannot release protection held in another native currency', function () {
    $math = new AvailabilityMath;
    $rate = ['id' => '1', 'base' => 'USD', 'quote' => 'QAR', 'value' => '0.5', 'observed_at' => '2026-10-18T00:00:00Z', 'effective_at' => '2026-10-18T00:00:00Z'];
    $actual = $math->calculate(Currency::QAR, ['QAR' => ['cash' => '100', 'protected' => '100', 'salary_count' => 1], 'USD' => ['cash' => '2', 'protected' => '1', 'monthly_commitments' => '1', 'monthly_overlap' => '1']], ['USD' => $rate], '2026-10-18T12:00:00Z', '0', '0', []);
    $forecast = $math->forecast($actual);
    expect($forecast['state'])->toBe('complete')->and($forecast['details']['remaining_protection']['minor'])->toBe('100')
        ->and($forecast['details']['bill_overlap']['minor'])->toBe('0')->and($forecast['details']['rounding_overlap_not_applied']['minor'])->toBe('1')
        ->and($forecast['free']['minor'])->toBe('0');
    $zero = $math->forecast($actual, ['commitments' => '0']);
    expect($zero['details']['remaining_protection']['minor'])->toBe('100')->and($zero['details']['rounding_overlap_not_applied']['minor'])->toBe('0')->and($zero['free']['minor'])->toBe('1');
});
