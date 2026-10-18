import assert from 'node:assert/strict';
import test from 'node:test';
import { planningMatchDraft } from '../resources/js/composables/planningMatchDraft.ts';

const values = new Map();
const storage = {
    getItem: (k) => values.get(k) ?? null,
    setItem: (k, v) => values.set(k, v),
    removeItem: (k) => values.delete(k),
};

await test('a hard reload restores attempted match details only for the original pending request identity', async () => {
    globalThis.window = { sessionStorage: storage };
    const draft = planningMatchDraft('owner-a:occurrence-1');
    draft.save('request-one', { journal_id: '19', amount: '147.25' });
    const reloaded =
        await import('../resources/js/composables/planningMatchDraft.ts?reload');
    assert.deepEqual(
        reloaded.planningMatchDraft('owner-a:occurrence-1').read('request-one'),
        { journal_id: '19', amount: '147.25' },
    );
    assert.equal(
        reloaded.planningMatchDraft('owner-a:occurrence-1').read('request-two'),
        null,
    );
    assert.equal(
        reloaded.planningMatchDraft('owner-b:occurrence-1').read('request-one'),
        null,
    );
    assert.equal(
        reloaded.planningMatchDraft('owner-a:occurrence-2').read('request-one'),
        null,
    );
});

await test('confirmed success clears pending details and stale storage cannot restore the next request', () => {
    const draft = planningMatchDraft('owner-a:occurrence-3');
    draft.save('request-three', { journal_id: '21', amount: '1.00' });
    draft.clear('request-three');
    assert.equal(draft.read('request-three'), null);
    assert.equal(values.has('hwo-pending-match:owner-a:occurrence-3'), false);
    values.set(
        'hwo-pending-match:owner-a:occurrence-3',
        JSON.stringify({
            request_id: 'request-three',
            journal_id: '21',
            amount: '1.00',
        }),
    );
    assert.equal(draft.read('request-four'), null);
});

await test('denied writes preserve current-session recovery and malformed tab data is ignored', async () => {
    globalThis.window = {
        sessionStorage: {
            ...storage,
            setItem: () => {
                throw new Error('Synthetic denied write');
            },
        },
    };
    const draft = planningMatchDraft('owner-a:occurrence-4');
    draft.save('request-four', { journal_id: '31', amount: '2.00' });
    assert.deepEqual(draft.read('request-four'), {
        journal_id: '31',
        amount: '2.00',
    });
    const fresh =
        await import('../resources/js/composables/planningMatchDraft.ts?malformed');
    for (const value of [
        'broken JSON',
        '{"request_id":"request-five","journal_id":{},"amount":"1.00"}',
        '{"request_id":"request-five","journal_id":"3","amount":"1e9"}',
    ]) {
        values.set('hwo-pending-match:owner-a:occurrence-5', value);
        assert.equal(
            fresh
                .planningMatchDraft('owner-a:occurrence-5')
                .read('request-five'),
            null,
        );
    }
    delete globalThis.window;
});

await test('pending exact JPY maximum and three-decimal amounts survive hard reload without numeric conversion', async () => {
    globalThis.window = { sessionStorage: storage };
    const fresh =
        await import('../resources/js/composables/planningMatchDraft.ts?exponents');
    for (const [scope, amount] of [
        ['jpy-max', '9223372036854775807'],
        ['kwd-three', '17.359'],
    ]) {
        planningMatchDraft(scope).save('exact-request', {
            journal_id: '32',
            amount,
        });
        assert.equal(
            fresh.planningMatchDraft(scope).read('exact-request')?.amount,
            amount,
        );
    }
    delete globalThis.window;
});
