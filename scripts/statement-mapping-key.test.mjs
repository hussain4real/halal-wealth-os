import assert from 'node:assert/strict';
import test from 'node:test';
import { statementMappingKey } from '../resources/js/composables/statementMappingKey.ts';

await test('mapping labels normalize the same boundary padding as PHP CSV cells', () => {
    const padding = String.fromCharCode(0, 9, 10, 11, 13, 32);
    assert.equal(statementMappingKey(`${padding}Salary${padding}`), 'Salary');
    assert.equal(statementMappingKey(padding), '');
    assert.equal(statementMappingKey('  Salary bonus  '), 'Salary bonus');
});

await test('Unicode spaces and line separators stay explicit label characters', () => {
    for (const edge of ['\u00a0', '\u2028', '\u2029', '\f']) {
        const label = `${edge}Salary${edge}`;
        assert.equal(statementMappingKey(label), label);
        assert.equal(statementMappingKey(` ${label} `), label);
    }
});

await test('normalization is idempotent and preserves interior whitespace and case', () => {
    for (const label of ['', 'Salary', ' Salary ', ' SALARY bonus\tpaid ']) {
        const key = statementMappingKey(label);
        assert.equal(statementMappingKey(key), key);
    }
    assert.equal(statementMappingKey(' Salary\tBonus '), 'Salary\tBonus');
});
