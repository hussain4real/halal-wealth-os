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

await test('a revised family on a later page replaces its hidden old version and retains unsaved classifications', () => {
    const rows = Array.from({ length: 24 }, (_, i) => ({
        schedule_id: String(i + 1),
        required: i === 0 ? '0' : '1',
    }));
    const families = new Map(
        rows.map((row) => [row.schedule_id, 'family-' + row.schedule_id]),
    );
    const result = module.reconcileCashRequirements(
        rows,
        [{ schedule_id: '121', family: 'family-21' }],
        families,
    );
    assert.equal(result.length, 24);
    assert.equal(
        result.some((row) => row.schedule_id === '21'),
        false,
    );
    assert.equal(result.find((row) => row.schedule_id === '121').required, '1');
    assert.equal(result[0].required, '0');
    assert.equal(families.get('121'), 'family-21');
});
await test('revisiting and revising a family keeps one row and the newest explicit choice', () => {
    const families = new Map([['21', 'family-21']]);
    const rows = [
        { schedule_id: '21', required: '1' },
        { schedule_id: '121', required: '0' },
    ];
    const first = module.reconcileCashRequirements(
        rows,
        [{ schedule_id: '121', family: 'family-21' }],
        families,
    );
    assert.deepEqual(first, [{ schedule_id: '121', required: '0' }]);
    const second = module.reconcileCashRequirements(
        first,
        [{ schedule_id: '221', family: 'family-21' }],
        families,
    );
    assert.deepEqual(second, [{ schedule_id: '221', required: '0' }]);
    assert.deepEqual(
        module.reconcileCashRequirements(
            second,
            [{ schedule_id: '221', family: 'family-21' }],
            families,
        ),
        second,
    );
});
await test('a newly visited family stays explicitly unanswered until the owner classifies it', () => {
    const result = module.reconcileCashRequirements(
        [],
        [{ schedule_id: '9007199254740993', family: 'new-family' }],
        new Map(),
    );
    assert.deepEqual(result, [
        { schedule_id: '9007199254740993', required: '' },
    ]);
    const draft = module.cashSettingsDraft(
        'new-family',
        'original-request',
        fields,
    );
    draft.save({ ...payload, requirements: JSON.stringify(result) });
    assert.equal(draft.load().requirements, JSON.stringify(result));
    draft.clear();
});
