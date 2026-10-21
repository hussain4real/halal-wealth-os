import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { stripTypeScriptTypes } from 'node:module';
import test from 'node:test';
const source = readFileSync(
    new URL('../resources/js/lib/decisionTranches.ts', import.meta.url),
    'utf8',
);
const { decisionTranches } = await import(
    'data:text/javascript;base64,' +
        Buffer.from(stripTypeScriptTypes(source)).toString('base64')
);
await test('saved proposal tranches retain exact money and stable identity after recovery', () => {
    const rows = [
        {
            key: 'initial',
            label: 'Initial',
            amount: '92233720368547758.07',
            condition: 'Evidence reviewed',
        },
        {
            key: 'reserve',
            label: 'Reserve',
            amount: '0.01',
            condition: 'Recheck',
        },
    ];
    assert.deepEqual(decisionTranches(JSON.stringify(rows)), rows);
});
await test('malformed or duplicate saved tranche identities cannot become an editable empty proposal', () => {
    for (const value of [
        '{',
        'null',
        '{}',
        '[null]',
        '[{"key":"x"}]',
        JSON.stringify([{ key: 'x', label: 'x', amount: 2, condition: 'x' }]),
        JSON.stringify(
            Array(25).fill({
                key: 'x',
                label: 'x',
                amount: '1',
                condition: 'x',
            }),
        ),
        JSON.stringify(
            Array(2).fill({
                key: 'x',
                label: 'x',
                amount: '1',
                condition: 'x',
            }),
        ),
    ])
        assert.equal(decisionTranches(value), null);
    assert.deepEqual(decisionTranches('[]'), []);
});
