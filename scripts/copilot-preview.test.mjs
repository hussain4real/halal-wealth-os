import assert from 'node:assert/strict';
import test from 'node:test';
import { copilotPreviewOutcome } from '../resources/js/composables/copilotPreviewOutcome.ts';

const reference = '5eaefea0-aafa-4a27-a48f-a030fcfc014a';
const outcome = (status, body) =>
    copilotPreviewOutcome({ status, body, reference, recovery: 'new' });

await test('uncertain server and malformed responses never authorize discarding the charged request identity', () => {
    for (const status of [500, 502, 503, 504, 408, 202, 204, 302]) {
        assert.deepEqual(outcome(status, { message: 'Unconfirmed outcome' }), {
            kind: 'uncertain',
        });
        assert.deepEqual(
            outcome(status, { status: 'already_charged', reference }),
            { kind: 'uncertain' },
        );
    }
    for (const body of [
        null,
        [],
        'bad',
        {},
        { status: 'unknown', reference },
        { status: 'complete', reference },
        { status: 'unavailable', reference: 'another-request' },
    ]) {
        assert.deepEqual(outcome(200, body), { kind: 'uncertain' });
    }
});

await test('only the same request receipt or definitive validation response ends recovery', () => {
    const evidence = { amount: '10.00', currency: 'QAR' };
    assert.deepEqual(
        outcome(200, { status: 'complete', reference, evidence }),
        { kind: 'complete', evidence },
    );
    for (const kind of ['already_charged', 'unavailable']) {
        assert.deepEqual(outcome(200, { status: kind, reference }), { kind });
    }
    for (const status of [400, 401, 403, 404, 409, 419, 422]) {
        assert.deepEqual(outcome(status, { message: 'Rejected' }), {
            kind: 'rejected',
        });
    }
    assert.deepEqual(outcome(429, { message: 'Limit reached' }), {
        kind: 'limited',
    });
    assert.deepEqual(outcome(422, {}), { kind: 'uncertain' });
});

await test('a later throttle or authorization error cannot settle an earlier unconfirmed admission', () => {
    assert.equal(outcome(500, { message: 'Unavailable' }).kind, 'uncertain');
    for (const status of [400, 401, 403, 404, 409, 419, 422, 429]) {
        assert.deepEqual(
            copilotPreviewOutcome({
                status,
                body: { message: 'Try later' },
                reference,
                recovery: 'unconfirmed',
            }),
            { kind: 'uncertain' },
        );
    }
    assert.deepEqual(
        copilotPreviewOutcome({
            status: 200,
            body: { status: 'already_charged', reference },
            reference,
            recovery: 'unconfirmed',
        }),
        { kind: 'already_charged' },
    );
});
