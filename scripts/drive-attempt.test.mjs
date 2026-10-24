import assert from 'node:assert/strict';
import test from 'node:test';
import {
    driveAttempt,
    isDriveReceipt,
} from '../resources/js/composables/driveAttempt.ts';
import { planningRequestIdentity } from '../resources/js/composables/planningRequestIdentity.ts';
import { portfolioRequestDraft } from '../resources/js/composables/portfolioRequestDraft.ts';

const values = new Map();
globalThis.window = {
    sessionStorage: {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    },
};

await test('two successful same-instance Drive submissions rotate both identity and validated draft binding', () => {
    const identity = planningRequestIdentity(
        'drive-same-instance',
        crypto.randomUUID(),
    );
    const attempt = driveAttempt(identity, (key) =>
        portfolioRequestDraft('drive-same-instance', key, [
            'review_token',
            'acknowledged',
        ]),
    );
    const first = attempt.save({
        review_token: 'a'.repeat(64),
        acknowledged: '1',
    });
    attempt.complete();
    assert.equal(attempt.load(), null);
    const second = attempt.save({
        review_token: 'b'.repeat(64),
        acknowledged: '1',
    });
    assert.notEqual(first.idempotency_key, second.idempotency_key);
    assert.equal(second.review_token, 'b'.repeat(64));
    attempt.complete();
    const third = attempt.save({
        review_token: 'c'.repeat(64),
        acknowledged: '1',
    });
    assert.notEqual(second.idempotency_key, third.idempotency_key);
    attempt.complete();
});

await test('uncertain Drive attempts keep the exact key and selectors despite a new displayed review', () => {
    const identity = planningRequestIdentity(
        'drive-interrupted',
        crypto.randomUUID(),
    );
    const store = (key) =>
        portfolioRequestDraft('drive-interrupted', key, [
            'review_token',
            'acknowledged',
        ]);
    const attempt = driveAttempt(identity, store);
    const first = attempt.save({
        review_token: 'a'.repeat(64),
        acknowledged: '1',
    });
    assert.deepEqual(
        attempt.save({ review_token: 'b'.repeat(64), acknowledged: '0' }),
        first,
    );
    const restored = driveAttempt(
        planningRequestIdentity('drive-interrupted', crypto.randomUUID()),
        store,
    );
    assert.deepEqual(restored.load(), first);
    restored.complete();
});

await test('only a matching request and operation receipt can unlock the Drive draft', () => {
    const key = crypto.randomUUID();
    assert.equal(
        isDriveReceipt(
            { id: '1', request_key: key, kind: 'report' },
            key,
            'confirm',
        ),
        true,
    );
    for (const body of [
        null,
        {},
        { id: '1' },
        { id: '1', request_key: 'foreign', kind: 'report' },
        { id: '1', request_key: key, kind: 'folder' },
        { id: '0', request_key: key, kind: 'report' },
    ])
        assert.equal(isDriveReceipt(body, key, 'confirm'), false);
});

delete globalThis.window;
