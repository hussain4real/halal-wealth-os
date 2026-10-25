import assert from 'node:assert/strict';
import test from 'node:test';
import { createHash } from 'node:crypto';
import {
    readFileSync,
    mkdtempSync,
    mkdirSync,
    writeFileSync,
    rmSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { evaluationBasis } from './answer-eval-basis.mjs';
import {
    validateLiveReport,
    releasePolicy,
    policyDigest,
} from './verify-answer-eval.mjs';
const digest = (x) => createHash('sha256').update(x).digest('hex');
const dataset = readFileSync('resources/copilot/evaluation/questions-v1.json');
const prompt = readFileSync('resources/copilot/answer-plan-v1.txt');
function syntheticValidatorFixture() {
    const cases = JSON.parse(dataset).cases.map((c, i) => ({
        id: c.id,
        observed_at: '2026-10-25T04:00:00Z',
        case_sha256: digest(JSON.stringify(c)),
        input_sha256: digest(
            JSON.stringify({ QUESTION: c.question, DOCUMENT: c.document }),
        ),
        http_status: 200,
        http_attempts: 1,
        handler_transfers: 1,
        native_curl_stats: true,
        model: 'gpt-5.4-2026-03-05',
        policy_sha256: policyDigest,
        payload_sha256: digest('explicit-stub-wire-' + i),
        evaluation: true,
        wire_bytes: 1000,
        max_input_tokens: 8192,
        max_output_tokens: 512,
        reasoning_effort: 'none',
        store: false,
        claim: c.claim,
        mode: c.mode,
        scope: c.scope,
        selected_tool:
            {
                cash: 'cash_availability',
                emergency: 'goals',
                nigeria: 'valuation_evidence',
                changes: 'valuation_evidence',
                assessments: 'shariah_history',
            }[c.claim.split('.')[0]] ?? null,
        response_id_sha256: digest('explicit-stub-test-receipt-' + i),
        usage: { input_tokens: 100, output_tokens: 20, total_tokens: 120 },
        checks: Object.fromEntries(
            [
                'selection',
                'tool_selection',
                'exact_facts',
                'citation_resolution',
                'source_membership',
                'uncertainty_and_dates',
                'safe_claims',
                'no_financial_writes',
                'once_only_charge',
                'budget_reserved_before_call',
            ].map((k) => [k, true]),
        ),
    }));
    return {
        version: 'live-answer-evaluation-v2',
        started_at: '2026-10-25T03:59:00Z',
        completed_at: '2026-10-25T04:01:00Z',
        result: 'PASS',
        synthetic_only: true,
        transport: 'actual-openai-responses',
        basis_sha256: 'a'.repeat(64),
        dataset_sha256: digest(dataset),
        prompt_sha256: digest(prompt),
        model: 'gpt-5.4-2026-03-05',
        store: false,
        max_output_tokens: 512,
        max_input_tokens: 8192,
        policy: structuredClone(releasePolicy),
        policy_sha256: policyDigest,
        cases,
        totals: { cases: 40, input_tokens: 4000, output_tokens: 800 },
        total_eval_cap_micro_usd: 2000000,
        reserved_total_micro_usd: 1128224,
        limitation:
            'retained observed receipt; not a provider-signed attestation',
    };
}
await test('retained live evidence validator rejects changed basis missing cases stubs wrong plans usage and unsafe checks', () => {
    const report = syntheticValidatorFixture();
    assert.equal(
        validateLiveReport(report, 'a'.repeat(64), dataset, prompt).cases,
        40,
    );
    for (const mutate of [
        (r) => (r.transport = 'stub'),
        (r) => (r.model = 'gpt-5.4-mini-2026-03-17'),
        (r) => (r.policy.case_reservation_micro_usd = 1),
        (r) => (r.cases[0].policy_sha256 = 'f'.repeat(64)),
        (r) => (r.cases[0].wire_bytes = 8192),
        (r) => (r.cases[0].max_input_tokens = 16384),
        (r) => {
            r.cases[0].usage.input_tokens = 8193;
            r.cases[0].usage.total_tokens = 8213;
        },
        (r) => (r.cases[0].handler_transfers = 2),
        (r) => (r.cases[0].native_curl_stats = false),
        (r) => (r.basis_sha256 = 'b'.repeat(64)),
        (r) => r.cases.pop(),
        (r) => (r.cases[1].claim = 'refuse'),
        (r) => (r.cases[0].response_id_sha256 = r.cases[1].response_id_sha256),
        (r) => (r.cases[0].usage.output_tokens = 513),
        (r) => (r.cases[0].checks.no_financial_writes = false),
        (r) => (r.reserved_total_micro_usd = 2000001),
        (r) => (r.prompt_sha256 = '0'.repeat(64)),
        (r) => (r.totals.input_tokens = 0),
    ]) {
        const invalid = structuredClone(report);
        mutate(invalid);
        assert.throws(
            () => validateLiveReport(invalid, 'a'.repeat(64), dataset, prompt),
            /BLOCKED/,
        );
    }
});
await test('evaluation basis binds new runtime prompt grader dataset and tests while normalizing only generated receipt reference', () => {
    const root = mkdtempSync(path.join(tmpdir(), 'hwo-eval-basis-'));
    try {
        assert.equal(spawnSync('git', ['init', '-q', root]).status, 0);
        for (const dir of [
            'app',
            'scripts',
            'tests',
            'resources/copilot',
            'docs/halal-wealth-os/runs',
        ])
            mkdirSync(path.join(root, dir), { recursive: true });
        const map = {
            manual_artifacts: {
                'm7p2-live-evaluation': {
                    sha256: '1'.repeat(64),
                    public_path:
                        'docs/halal-wealth-os/runs/M7-P2-live-evaluation.json',
                    scope: 'Actual synthetic evaluation',
                },
            },
        };
        writeFileSync(
            path.join(root, 'scripts/acceptance-map.json'),
            JSON.stringify(map),
        );
        for (const file of [
            'app/Answer.php',
            'scripts/grader.php',
            'tests/Answer.php',
            'resources/copilot/prompt.txt',
            'resources/copilot/dataset.json',
        ])
            writeFileSync(path.join(root, file), 'initial');
        const before = evaluationBasis(root);
        map.manual_artifacts['m7p2-live-evaluation'].sha256 = '2'.repeat(64);
        writeFileSync(
            path.join(root, 'scripts/acceptance-map.json'),
            JSON.stringify(map),
        );
        writeFileSync(
            path.join(
                root,
                'docs/halal-wealth-os/runs/M7-P2-live-evaluation.json',
            ),
            'generated',
        );
        assert.equal(evaluationBasis(root), before);
        for (const file of [
            'app/Answer.php',
            'scripts/grader.php',
            'tests/Answer.php',
            'resources/copilot/prompt.txt',
            'resources/copilot/dataset.json',
        ]) {
            writeFileSync(path.join(root, file), 'changed');
            assert.notEqual(evaluationBasis(root), before);
            writeFileSync(path.join(root, file), 'initial');
        }
        map.manual_artifacts['m7p2-live-evaluation'].scope = 'weakened scope';
        writeFileSync(
            path.join(root, 'scripts/acceptance-map.json'),
            JSON.stringify(map),
        );
        assert.notEqual(evaluationBasis(root), before);
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
});

await test('live gate refuses a current-basis dataset that removes a required finance topic', () => {
    const invalid = JSON.parse(dataset);
    for (const c of invalid.cases)
        if (c.claim.startsWith('nigeria.')) c.claim = 'cash.available';
    const bytes = Buffer.from(JSON.stringify(invalid));
    const report = syntheticValidatorFixture();
    report.dataset_sha256 = digest(bytes);
    assert.throws(
        () => validateLiveReport(report, 'a'.repeat(64), bytes, prompt),
        /canonical topic/,
    );
});
