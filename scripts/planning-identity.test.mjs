import assert from 'node:assert/strict';
import test from 'node:test';
import { planningRequestIdentity } from '../resources/js/composables/planningRequestIdentity.ts';

function storage() {
    const values = new Map();
    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };
}

await test('confirmed success advances Back-restored drafts while interrupted delivery preserves the original identity', () => {
    globalThis.window = { sessionStorage: storage() };
    const original = crypto.randomUUID();
    const first = planningRequestIdentity('owner-a:category:new', original);
    assert.equal(first.current, original);
    // No success callback occurred: restore and retry the identical request.
    assert.equal(
        planningRequestIdentity('owner-a:category:new', original).current,
        original,
    );
    const next = first.complete();
    assert.notEqual(next, original);
    assert.equal(
        planningRequestIdentity('owner-a:category:new', original).current,
        next,
    );
    delete globalThis.window;
});

await test('page reload reads the persisted pending UUID without sharing identities across owners or operations', async () => {
    globalThis.window = { sessionStorage: storage() };
    const original = crypto.randomUUID();
    const expected = planningRequestIdentity(
        'owner-a:schedule:new',
        original,
    ).complete();
    const reloaded =
        await import('../resources/js/composables/planningRequestIdentity.ts?reload');
    assert.equal(
        reloaded.planningRequestIdentity('owner-a:schedule:new', original)
            .current,
        expected,
    );
    const other = crypto.randomUUID();
    assert.equal(
        reloaded.planningRequestIdentity('owner-b:schedule:new', other).current,
        other,
    );
    assert.equal(
        reloaded.planningRequestIdentity('owner-a:classification:7', other)
            .current,
        other,
    );
    delete globalThis.window;
});

await test('failed storage writes cannot replace a newer app-session identity with a stale stored UUID', () => {
    const original = crypto.randomUUID();
    globalThis.window = {
        sessionStorage: {
            getItem: () => original,
            setItem: () => {
                throw new Error('Synthetic storage write denied');
            },
        },
    };
    const first = planningRequestIdentity('owner-a:match:occurrence', original);
    const next = first.complete();
    assert.notEqual(next, original);
    assert.equal(
        planningRequestIdentity('owner-a:match:occurrence', original).current,
        next,
    );
    delete globalThis.window;
});

await test('unavailable storage retains safe current-session Back behavior and malformed stored UUIDs are ignored', () => {
    const original = crypto.randomUUID();
    globalThis.window = {
        sessionStorage: { getItem: () => 'malformed-value', setItem: () => {} },
    };
    assert.equal(
        planningRequestIdentity('owner-a:release:8', original).current,
        original,
    );
    globalThis.window = {
        sessionStorage: {
            getItem: () => {
                throw new Error('Synthetic storage read denied');
            },
            setItem: () => {
                throw new Error('Synthetic storage write denied');
            },
        },
    };
    const next = planningRequestIdentity(
        'owner-a:release:9',
        original,
    ).complete();
    assert.equal(
        planningRequestIdentity('owner-a:release:9', original).current,
        next,
    );
    delete globalThis.window;
});
