import test from 'node:test';
import assert from 'node:assert/strict';
import { dashboardTargets } from '../resources/js/lib/dashboardTargets.ts';
await test('target parser preserves exact high precision text', () => {
    const rows = [
        {
            currency: 'QAR',
            target: '99.999999999999',
            tolerance: '0.000000000001',
        },
    ];
    assert.deepEqual(dashboardTargets(JSON.stringify(rows)), {
        ok: true,
        rows,
    });
});
await test('target recovery rejects malformed and numeric rows instead of coercing money', () => {
    for (const value of [
        '{}',
        '[null]',
        '[{"currency":"QAR","target":100,"tolerance":"0"}]',
        'bad',
    ])
        assert.equal(dashboardTargets(value).ok, false);
});
await test('empty targets are honestly unconfigured', () =>
    assert.deepEqual(dashboardTargets('[]'), { ok: true, rows: [] }));
