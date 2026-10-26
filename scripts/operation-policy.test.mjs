import assert from 'node:assert/strict';
import test from 'node:test';
import { synchronizeOperationPolicy } from '../resources/js/lib/operation-policy.ts';

await test('refresh preserves only actually edited fields and cannot silently restore untouched higher limits', () => {
    const old = {
        generation: '1',
        paused: false,
        requests_daily: 100,
        units_daily: 100,
        retention_days: 7,
    };
    const next = {
        generation: '3',
        paused: true,
        requests_daily: 2,
        units_daily: 3,
        retention_days: 1,
    };
    assert.deepEqual(synchronizeOperationPolicy(old, old, next), next);
    const draft = { ...old, retention_days: 5 };
    const refreshed = synchronizeOperationPolicy(draft, old, next);
    assert.deepEqual(refreshed, { ...next, retention_days: 5 });
    assert.deepEqual(
        synchronizeOperationPolicy(refreshed, next, {
            ...next,
            generation: '4',
            units_daily: 1,
        }),
        { ...next, generation: '4', units_daily: 1, retention_days: 5 },
    );
});
