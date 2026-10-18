import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
const source = readFileSync(
    new URL(
        '../resources/js/composables/portfolioRequestDraft.ts',
        import.meta.url,
    ),
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
        .portfolioRequestDraft('owner1:reserve1', 'request-one', [
            'amount',
            'reason',
        ])
        .save(payload);
    assert.deepEqual(
        stripped
            .portfolioRequestDraft('owner1:reserve1', 'request-one', [
                'amount',
                'reason',
            ])
            .load(),
        payload,
    );
    assert.equal(
        stripped
            .portfolioRequestDraft('owner2:reserve1', 'request-one', [
                'amount',
                'reason',
            ])
            .load(),
        null,
    );
    assert.equal(
        stripped
            .portfolioRequestDraft('owner1:reserve1', 'request-two', [
                'amount',
                'reason',
            ])
            .load(),
        null,
    );
});
await test('confirmed clear removes history draft before identity rotates', () => {
    const d = stripped.portfolioRequestDraft('owner1:reserve1', 'request-one', [
        'amount',
        'reason',
    ]);
    d.clear();
    assert.equal(d.load(), null);
    assert.equal(store.size, 0);
});
await test('three decimal payload and portfolio details remain strings', () => {
    const d = stripped.portfolioRequestDraft('owner1:create', 'request-three', [
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
        'hwo:portfolio:attempt:invalid',
        '{"idempotency_key":"request-one","amount":100,"reason":"bad"}',
    );
    assert.equal(
        stripped
            .portfolioRequestDraft('invalid', 'request-one', [
                'amount',
                'reason',
            ])
            .load(),
        null,
    );
    assert.throws(() =>
        stripped
            .portfolioRequestDraft('invalid', 'request-one', [
                'amount',
                'reason',
            ])
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
    const d = stripped.portfolioRequestDraft('no-storage', 'request-one', [
        'amount',
        'reason',
    ]);
    d.save(payload);
    assert.deepEqual(d.load(), payload);
    d.clear();
    assert.equal(d.load(), null);
});

await test('complete opening attempts preserve exact units prices manual value and unknown basis across reloads', () => {
    const fields = ['prior_id', 'as_of', 'source', 'reason', 'rows'];
    const rows = [
        {
            asset_id: '9007199254740993',
            quantity: '1.234567890123',
            unit_price: '2.000000000001',
            manual_value: '',
            basis_known: '0',
            cost_basis: '',
        },
    ];
    const p = {
        idempotency_key: 'opening-request',
        prior_id: '',
        as_of: '2026-10-18',
        source: 'Synthetic statement',
        reason: 'Synthetic opening',
        rows: JSON.stringify(rows),
    };
    const d = stripped.portfolioRequestDraft(
        'opening-exact',
        'opening-request',
        fields,
    );
    d.save(p);
    assert.deepEqual(d.load(), p);
    assert.deepEqual(JSON.parse(d.load().rows), rows);
    for (const invalid of [
        JSON.stringify([{ ...rows[0], quantity: 1.234 }]),
        JSON.stringify([{ ...rows[0], owner_id: '2' }]),
        JSON.stringify(Array(1001).fill(rows[0])),
        '{}',
        'not-json',
    ])
        assert.throws(() => d.save({ ...p, rows: invalid }));
    assert.deepEqual(d.load(), p);
});

await test('metadata strings use the server Unicode character bound rather than UTF-16 units', () => {
    const d = stripped.portfolioRequestDraft(
        'unicode-source',
        'unicode-attempt',
        ['source'],
    );
    const p = { idempotency_key: 'unicode-attempt', source: '🟢'.repeat(180) };
    d.save(p);
    assert.deepEqual(d.load(), p);
    assert.throws(() => d.save({ ...p, source: '🟢'.repeat(181) }));
});
