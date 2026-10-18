import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { stripTypeScriptTypes } from 'node:module';
const module = await import(
    'data:text/javascript;base64,' +
        Buffer.from(
            stripTypeScriptTypes(
                readFileSync(
                    new URL(
                        '../resources/js/composables/cashSettingsDraft.ts',
                        import.meta.url,
                    ),
                    'utf8',
                ),
            ),
        ).toString('base64')
);
const store = new Map();
globalThis.window = {
    sessionStorage: {
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => store.set(key, value),
        removeItem: (key) => store.delete(key),
    },
};
const fields = ['prior_id', 'requirements', 'overlaps', 'investment_goals'];
const payload = {
    idempotency_key: 'original-request',
    prior_id: '17',
    requirements: JSON.stringify(
        Array.from({ length: 25 }, (_, i) => ({
            schedule_id: String(i + 1),
            required: i % 2 ? '1' : '0',
        })),
    ),
    overlaps: '[]',
    investment_goals: '["9"]',
};
await test('large exact settings payload survives recovery without replacing its prior version or request identity', () => {
    const draft = module.cashSettingsDraft(
        'owner1',
        'original-request',
        fields,
    );
    draft.save(payload);
    assert.deepEqual(draft.load(), payload);
    assert.equal(
        module.cashSettingsDraft('owner1', 'different-request', fields).load(),
        null,
    );
    assert.equal(
        module.cashSettingsDraft('owner2', 'original-request', fields).load(),
        null,
    );
});
await test('confirmed clear removes the old attempt before a new request identity is used', () => {
    module.cashSettingsDraft('owner1', 'original-request', fields).clear();
    assert.equal(
        module.cashSettingsDraft('owner1', 'original-request', fields).load(),
        null,
    );
    assert.equal(store.size, 0);
});
await test('corrupted JSON lists and unexpected nested fields never crash the settings form', () => {
    for (const value of [
        '{bad',
        '{}',
        '[{"schedule_id":"1","required":"1","owner_id":"2"}]',
    ]) {
        store.set(
            'hwo:cash-settings:attempt:invalid',
            JSON.stringify({ ...payload, requirements: value }),
        );
        assert.equal(
            module
                .cashSettingsDraft('invalid', 'original-request', fields)
                .load(),
            null,
        );
    }
});
await test('a denied storage operation retains the exact attempt within the current app session', () => {
    const original = globalThis.window;
    globalThis.window = {
        get sessionStorage() {
            throw new Error('Storage denied');
        },
    };
    const draft = module.cashSettingsDraft(
        'denied',
        'original-request',
        fields,
    );
    draft.save(payload);
    assert.deepEqual(draft.load(), payload);
    draft.clear();
    assert.equal(draft.load(), null);
    globalThis.window = original;
});
await test('unexpected authority fields and oversized lists cannot become a recovered pending request', () => {
    const draft = module.cashSettingsDraft('shape', 'original-request', fields);
    assert.throws(() => draft.save({ ...payload, owner_id: '2' }));
    assert.throws(() =>
        draft.save({
            ...payload,
            investment_goals: JSON.stringify(Array(1001).fill('1')),
        }),
    );
});
