import assert from 'node:assert/strict';
import test from 'node:test';
import {
    parseGroundedAnswer,
    displayAnswerFact,
} from '../resources/js/composables/copilotAnswer.ts';
const answer = {
    schema: 'grounded-answer-v1',
    claim: 'cash.available',
    mode: 'recorded',
    state: 'answered',
    text: 'Recorded evidence',
    scope: 'Current facts only',
    generated_at: '2026-10-20T23:59:59Z',
    as_of: '2026-10-20T23:59:59Z',
    captured_at: '2026-10-20T23:59:59Z',
    completeness: 'complete',
    limitations: ['Not a forecast'],
    facts: [
        {
            key: 'cash.available',
            kind: 'money',
            label: 'Available',
            value: { amount: '750.00', currency: 'QAR' },
        },
    ],
    citations: [
        {
            kind: 'cash_settings',
            record_id: '1',
            label: 'Explicit settings',
            href: '/copilot/source/cash_settings/1',
        },
    ],
};
await test('typed answer receipt preserves exact money and the cash settings evidence route', () => {
    const parsed = parseGroundedAnswer(answer);
    assert.ok(parsed);
    assert.equal(displayAnswerFact(parsed.facts[0]), '750.00 QAR');
    assert.equal(parsed.citations[0].kind, 'cash_settings');
    assert.equal(
        displayAnswerFact({
            key: 'x',
            label: 'Missing',
            kind: 'money',
            value: null,
        }),
        'Unavailable — missing or unsupported evidence',
    );
    assert.equal(
        displayAnswerFact({
            key: 'x',
            label: 'Percent',
            kind: 'percent',
            value: '20.0000',
        }),
        '20.0000%',
    );
    assert.equal(
        displayAnswerFact({
            key: 'x',
            label: 'List',
            kind: 'records',
            value: [],
        }),
        '[]',
    );
});
await test('malformed receipts and arbitrary urls cannot release a pending answer', () => {
    for (const bad of [
        null,
        [],
        {},
        { ...answer, schema: 'wrong' },
        { ...answer, facts: [{}] },
        {
            ...answer,
            citations: [
                { ...answer.citations[0], href: 'https://evil.example' },
            ],
        },
        { ...answer, citations: [{ ...answer.citations[0], kind: 'users' }] },
        {
            ...answer,
            citations: [{ ...answer.citations[0], record_id: '../1' }],
        },
        { ...answer, limitations: [1] },
    ])
        assert.equal(parseGroundedAnswer(bad), null);
});
