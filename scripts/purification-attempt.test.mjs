import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { stripTypeScriptTypes } from 'node:module';
const load = async (name) =>
    import(
        'data:text/javascript;base64,' +
            Buffer.from(
                stripTypeScriptTypes(
                    readFileSync(
                        new URL(
                            '../resources/js/composables/' + name + '.ts',
                            import.meta.url,
                        ),
                        'utf8',
                    ),
                ),
            ).toString('base64')
    );
const { purificationAttemptScope: scope } = await load(
    'purificationAttemptScope',
);
const { portfolioRequestDraft: draft } = await load('portfolioRequestDraft');
const store = new Map();
globalThis.window = {
    sessionStorage: {
        getItem: (k) => store.get(k) ?? null,
        setItem: (k, v) => store.set(k, v),
        removeItem: (k) => store.delete(k),
    },
};
await test('create and revise attempts with identical numeric IDs cannot collide', () => {
    const fields = ['source_instruction'];
    const payload = {
        idempotency_key: 'synthetic-key',
        source_instruction: 'x'.repeat(2000),
    };
    draft(
        'owner1:' + scope('obligation-create', '42'),
        'synthetic-key',
        fields,
        { source_instruction: 2000 },
    ).save(payload);
    assert.equal(
        draft(
            'owner1:' + scope('obligation-revise', '42'),
            'synthetic-key',
            fields,
            { source_instruction: 2000 },
        ).load(),
        null,
    );
    assert.deepEqual(
        draft(
            'owner1:' + scope('obligation-create', '42'),
            'synthetic-key',
            fields,
            { source_instruction: 2000 },
        ).load(),
        payload,
    );
});
await test('a series-scoped lost-response allocation preserves exact old version across new current version', () => {
    const fields = ['obligation_id', 'payment_id', 'amount', 'reason'];
    const payload = {
        idempotency_key: 'synthetic-allocation',
        obligation_id: '100',
        payment_id: '9223372036854775807',
        amount: '1.000',
        reason: 'Synthetic committed attempt',
    };
    const identity = 'owner1:' + scope('allocation', 'synthetic-series');
    draft(identity, payload.idempotency_key, fields).save(payload);
    assert.deepEqual(
        draft(
            'owner1:' + scope('allocation', 'synthetic-series'),
            payload.idempotency_key,
            fields,
        ).load(),
        payload,
    );
    assert.equal(
        draft(
            'owner2:' + scope('allocation', 'synthetic-series'),
            payload.idempotency_key,
            fields,
        ).load(),
        null,
    );
    assert.equal(
        draft(
            'owner1:' + scope('allocation', 'other-series'),
            payload.idempotency_key,
            fields,
        ).load(),
        null,
    );
});
await test('a committed release attempt remains reachable independently of its updated disclosure state', () => {
    const payload = {
        idempotency_key: 'synthetic-release',
        reason: 'Synthetic retained release',
    };
    const identity = 'owner1:' + scope('release', '92');
    const attempt = draft(identity, payload.idempotency_key, ['reason']);
    attempt.save(payload);
    assert.deepEqual(
        draft(identity, payload.idempotency_key, ['reason']).load(),
        payload,
    );
    attempt.clear();
    assert.equal(attempt.load(), null);
});
