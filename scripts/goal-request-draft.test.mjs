import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
const source = readFileSync(
    new URL('../resources/js/composables/goalRequestDraft.ts', import.meta.url),
    'utf8',
);
const stripped = await import(
    'data:text/javascript;base64,' +
        Buffer.from(
            (await import('node:module')).stripTypeScriptTypes(source),
        ).toString('base64')
);
const store = new Map();
globalThis.window = {
    sessionStorage: {
        getItem: (k) => store.get(k) ?? null,
        setItem: (k, v) => store.set(k, v),
        removeItem: (k) => store.delete(k),
    },
};
const payload = {
    idempotency_key: 'request-one',
    amount: '9223372036854775807',
    reason: 'Synthetic claim',
};
await test('an interrupted exact payload restores only with matching owner scope and pending identity', () => {
    stripped
        .goalRequestDraft('owner1:reserve1', 'request-one', [
            'amount',
            'reason',
        ])
        .save(payload);
    assert.deepEqual(
        stripped
            .goalRequestDraft('owner1:reserve1', 'request-one', [
                'amount',
                'reason',
            ])
            .load(),
        payload,
    );
    assert.equal(
        stripped
            .goalRequestDraft('owner2:reserve1', 'request-one', [
                'amount',
                'reason',
            ])
            .load(),
        null,
    );
    assert.equal(
        stripped
            .goalRequestDraft('owner1:reserve1', 'request-two', [
                'amount',
                'reason',
            ])
            .load(),
        null,
    );
});
await test('confirmed clear removes history draft before identity rotates', () => {
    const d = stripped.goalRequestDraft('owner1:reserve1', 'request-one', [
        'amount',
        'reason',
    ]);
    d.clear();
    assert.equal(d.load(), null);
    assert.equal(store.size, 0);
});
await test('three decimal payload and whole new goal details remain strings', () => {
    const d = stripped.goalRequestDraft('owner1:create', 'request-three', [
        'opening',
        'currency',
        'account_id',
    ]);
    const p = {
        idempotency_key: 'request-three',
        opening: '1234.567',
        currency: 'KWD',
        account_id: '15',
    };
    d.save(p);
    assert.deepEqual(d.load(), p);
});
await test('corrupt or unexpected persisted fields cannot replace a form attempt', () => {
    store.set(
        'hwo:goal:attempt:invalid',
        '{"idempotency_key":"request-one","amount":100,"reason":"bad"}',
    );
    assert.equal(
        stripped
            .goalRequestDraft('invalid', 'request-one', ['amount', 'reason'])
            .load(),
        null,
    );
    assert.throws(() =>
        stripped
            .goalRequestDraft('invalid', 'request-one', ['amount', 'reason'])
            .save({ ...payload, extra: 'forged' }),
    );
});
await test('denied session storage retains safe current-session retry state', () => {
    globalThis.window = {
        sessionStorage: {
            getItem: () => {
                throw new Error('denied');
            },
            setItem: () => {
                throw new Error('denied');
            },
            removeItem: () => {
                throw new Error('denied');
            },
        },
    };
    const d = stripped.goalRequestDraft('no-storage', 'request-one', [
        'amount',
        'reason',
    ]);
    d.save(payload);
    assert.deepEqual(d.load(), payload);
    d.clear();
    assert.equal(d.load(), null);
});
