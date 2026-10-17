import assert from 'node:assert/strict';
import test from 'node:test';
import { currencyDisplay } from '../resources/js/lib/currencyDisplay.ts';

await test('currency display preserves exact caller decimal text including large and fractional values', () => {
    assert.equal(
        currencyDisplay('9007199254740993.00000001', 'USD'),
        '9007199254740993.00000001 USD',
    );
    assert.equal(currencyDisplay('-0.00000001', 'BTC'), '-0.00000001 BTC');
    assert.equal(currencyDisplay('0', 'JPY'), '0 JPY');
});

await test('currency display distinguishes missing or malformed values from zero', () => {
    for (const [amount, currency] of [
        [null, 'USD'],
        ['0', null],
        ['1e6', 'USD'],
        ['NaN', 'USD'],
        ['1', 'usd'],
        ['<script>', 'USD'],
    ]) {
        assert.equal(currencyDisplay(amount, currency), null);
    }
});
