import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { stripTypeScriptTypes } from 'node:module';

const source = readFileSync(
    new URL(
        '../resources/js/composables/openingAssessmentGuidance.ts',
        import.meta.url,
    ),
    'utf8',
);
const { openingAssessmentGuidance } = await import(
    'data:text/javascript;base64,' +
        Buffer.from(stripTypeScriptTypes(source)).toString('base64')
);
const summary = (classification = 'compliant') => ({
    classification,
    conflict: false,
    overdue: false,
    review_date_missing: false,
    other_reference_evidence: false,
    source_count: 1,
    guidance_state:
        classification === 'compliant' ? 'recorded_compliant' : 'unresolved',
    guidance: 'Synthetic current evidence guidance',
    factual_recording: 'Factual recording remains available',
});
const reply = (ids, classification = 'compliant') =>
    new Response(
        JSON.stringify({
            cutoff: '2026-10-19T12:00:00Z',
            items: ids.map((asset_id) => ({
                asset_id,
                summary: summary(classification),
            })),
        }),
        { headers: { 'Content-Type': 'application/json' } },
    );
const deferred = () => {
    let resolve;
    const promise = new Promise((complete) => {
        resolve = complete;
    });
    return { promise, resolve };
};

await test('visible and selected references deduplicate in a private bounded current read without changing the financial attempt', async () => {
    const attempt = Object.freeze({
        idempotency_key: 'synthetic-retained-opening',
        as_of: '2026-10-18',
        rows: '[{"asset_id":"1","quantity":"1.25","acquired_on":"2026-10-17"}]',
    });
    const before = JSON.stringify(attempt);
    const states = [];
    const loader = openingAssessmentGuidance(
        '/portfolio/1/opening/assessment-guidance',
        (state) => states.push(state),
        async (url, options) => {
            assert.deepEqual(
                new URL(url, 'https://example.test').searchParams.getAll(
                    'assets[]',
                ),
                ['1', '2'],
            );
            assert.equal(options.cache, 'no-store');
            assert.equal(options.credentials, 'same-origin');
            assert.equal(options.headers.Accept, 'application/json');
            return reply(['1', '2']);
        },
    );
    await loader.refresh(['1', '2', '1']);
    assert.equal(states[0].status, 'loading');
    assert.equal(states.at(-1).status, 'ready');
    assert.equal(states.at(-1).cutoff, '2026-10-19T12:00:00Z');
    assert.deepEqual([...states.at(-1).items.keys()], ['1', '2']);
    assert.equal(JSON.stringify(attempt), before);
    loader.dispose();
});

await test('oversized and malformed reference sets fail closed and empty views clear prior state', async () => {
    const states = [];
    let calls = 0;
    const loader = openingAssessmentGuidance(
        '/guidance',
        (state) => states.push(state),
        async () => {
            calls++;
            return reply(['1']);
        },
    );
    for (const ids of [
        Array.from({ length: 22 }, (_, n) => String(n + 1)),
        ['0'],
        ['1', 'not-an-id'],
    ]) {
        await loader.refresh(ids);
        assert.equal(states.at(-1).status, 'unavailable');
        assert.equal(states.at(-1).items.size, 0);
    }
    assert.equal(calls, 0);
    await loader.refresh([]);
    assert.equal(states.at(-1).status, 'ready');
    assert.equal(states.at(-1).items.size, 0);
    loader.dispose();
});

await test('a delayed old response cannot attach a compliant status to a newer selection', async () => {
    const first = deferred(),
        second = deferred();
    const states = [];
    let calls = 0;
    let firstSignal;
    const loader = openingAssessmentGuidance(
        '/guidance',
        (state) => states.push(state),
        (_url, options) => {
            calls++;
            if (calls === 1) firstSignal = options.signal;
            return calls === 1 ? first.promise : second.promise;
        },
    );
    const oldRequest = loader.refresh(['1']);
    const newRequest = loader.refresh(['2']);
    assert.equal(firstSignal.aborted, true);
    assert.equal(states.at(-1).items.size, 0);
    second.resolve(reply(['2'], 'questionable'));
    await newRequest;
    first.resolve(reply(['1']));
    await oldRequest;
    assert.deepEqual([...states.at(-1).items.keys()], ['2']);
    assert.equal(states.at(-1).items.get('2').classification, 'questionable');
    loader.dispose();
});

await test('refresh clears a previously compliant response before a failed current read', async () => {
    const states = [];
    let fail = false;
    const loader = openingAssessmentGuidance(
        '/guidance',
        (state) => states.push(state),
        async () => (fail ? new Response('', { status: 503 }) : reply(['1'])),
    );
    await loader.refresh(['1']);
    assert.equal(states.at(-1).items.get('1').classification, 'compliant');
    fail = true;
    const refresh = loader.refresh(['1']);
    assert.equal(states.at(-1).status, 'loading');
    assert.equal(states.at(-1).items.size, 0);
    await refresh;
    assert.equal(states.at(-1).status, 'unavailable');
    assert.equal(states.at(-1).cutoff, null);
    assert.equal(states.at(-1).items.size, 0);
    loader.dispose();
});

await test('malformed or mismatched summary responses cannot create a displayed classification', async () => {
    const valid = {
        cutoff: '2026-10-19T12:00:00Z',
        items: [{ asset_id: '1', summary: summary() }],
    };
    for (const payload of [
        null,
        { ...valid, cutoff: 'yesterday' },
        { ...valid, items: [] },
        { ...valid, items: [{ asset_id: '2', summary: summary() }] },
        {
            ...valid,
            items: [
                { asset_id: '1', summary: { ...summary(), conflict: 'false' } },
            ],
        },
        {
            ...valid,
            items: [
                {
                    asset_id: '1',
                    summary: { ...summary(), classification: 'certified' },
                },
            ],
        },
    ]) {
        const states = [];
        const loader = openingAssessmentGuidance(
            '/guidance',
            (state) => states.push(state),
            async () => new Response(JSON.stringify(payload)),
        );
        await loader.refresh(['1']);
        assert.equal(states.at(-1).status, 'unavailable');
        assert.equal(states.at(-1).items.size, 0);
        loader.dispose();
    }
});

await test('leaving the form invalidates late responses and further refreshes', async () => {
    const pending = deferred();
    const states = [];
    let signal;
    const loader = openingAssessmentGuidance(
        '/guidance',
        (state) => states.push(state),
        (_url, options) => {
            signal = options.signal;
            return pending.promise;
        },
    );
    const reading = loader.refresh(['1']);
    loader.dispose();
    assert.equal(signal.aborted, true);
    pending.resolve(reply(['1']));
    await reading;
    await loader.refresh(['2']);
    assert.equal(states.length, 1);
    assert.equal(states[0].status, 'loading');
});
