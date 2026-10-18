<?php

use App\Domain\Portfolio\BookCost;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('rational partial allocations conserve every minor unit through final disposal', function () {
    $basis = '101';
    $allocated = [];
    foreach (['3', '2', '1'] as $held) {
        $part = BookCost::allocate($basis, '1', $held);
        $allocated[] = $part;
        $basis = bcsub($basis, $part, 0);
    }
    expect($allocated)->toBe(['34', '34', '33'])->and($basis)->toBe('0');
});
test('allocation retains smallest exact fractional units and huge intermediate integers', function () {
    expect(BookCost::allocate('9223372036854775807', '0.000000000001', '0.000000000002'))->toBe('4611686018427387904');
    expect(BookCost::allocate('19', '999999999999999999.999999999999', '999999999999999999.999999999999'))->toBe('19');
});
test('split preserves representable precision and rejects invented fractional residue', function () {
    expect(BookCost::split('1.234567890123', '2', '1'))->toBe('2.469135780246');
    expect(fn () => BookCost::split('0.000000000001', '1', '3'))->toThrow(ValidationException::class);
});
