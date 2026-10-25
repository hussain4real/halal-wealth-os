import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { evaluationBasis, liveReport, root } from './answer-eval-basis.mjs';
import path from 'node:path';
import { isDeepStrictEqual } from 'node:util';
const digest = (x) => createHash('sha256').update(x).digest('hex');
export const releasePolicy = Object.freeze(
    JSON.parse(
        readFileSync(
            path.join(root, 'resources/copilot/evaluation/policy-v2.json'),
        ),
    ),
);
const approvedPolicy = {
    version: 'answer-evaluation-policy-v2',
    model: 'gpt-5.4-2026-03-05',
    reasoning_effort: 'none',
    store: false,
    max_input_tokens: 8192,
    max_output_tokens: 512,
    wire_overhead_tokens: 1024,
    input_price_micro_usd_per_million: 2500000,
    output_price_micro_usd_per_million: 15000000,
    case_reservation_micro_usd: 28160,
    total_cap_micro_usd: 2000000,
    production_reservation_tokens: 16384,
};
if (!isDeepStrictEqual(releasePolicy, approvedPolicy))
    throw Error('BLOCKED M7-P2 evaluation pricing/model policy');
export const policyDigest = digest(JSON.stringify(releasePolicy));
export function validateLiveReport(report, basis, dataset, prompt) {
    const fail = (s) => {
        throw Error('BLOCKED M7-P2 live evaluation: ' + s);
    };
    if (
        report.version !== 'live-answer-evaluation-v2' ||
        report.result !== 'PASS' ||
        report.synthetic_only !== true ||
        report.transport !== 'actual-openai-responses' ||
        report.basis_sha256 !== basis ||
        report.dataset_sha256 !== digest(dataset) ||
        report.prompt_sha256 !== digest(prompt) ||
        report.model !== releasePolicy.model ||
        !isDeepStrictEqual(report.policy, releasePolicy) ||
        report.policy_sha256 !== policyDigest ||
        report.max_input_tokens !== releasePolicy.max_input_tokens ||
        report.store !== false ||
        report.max_output_tokens !== 512
    )
        fail('identity or current basis');
    const data = JSON.parse(dataset);
    if (
        data.version !== 'grounded-answers-eval-v1' ||
        data.synthetic_only !== true ||
        !Array.isArray(data.cases)
    )
        fail('dataset identity');
    const cases = data.cases;
    const fields = {
        cash: [
            'overview',
            'available',
            'raw',
            'buffer',
            'shortfall',
            'commitments',
        ],
        emergency: [
            'overview',
            'target',
            'backed',
            'remaining',
            'progress',
            'coverage',
        ],
        nigeria: [
            'overview',
            'amount',
            'share',
            'denominator',
            'coverage',
            'components',
        ],
        changes: ['overview', 'amount', 'return', 'flows', 'native', 'fx'],
        assessments: [
            'overview',
            'classification',
            'review',
            'provider',
            'methodology',
            'count',
        ],
    };
    const finance = new Set(
        cases.filter((c) => c.category === 'finance').map((c) => c.claim),
    );
    for (const [topic, claims] of Object.entries(fields))
        for (const field of claims)
            if (!finance.has(topic + '.' + field))
                fail('canonical topic/claim coverage');
    const started = Date.parse(report.started_at),
        completed = Date.parse(report.completed_at);
    if (
        !Number.isFinite(started) ||
        !Number.isFinite(completed) ||
        completed < started ||
        completed > Date.now() + 300000
    )
        fail('observation timestamps');
    if (
        cases.filter((c) => c.category === 'finance').length < 30 ||
        cases.filter((c) => c.category === 'adversarial').length < 10 ||
        report.cases?.length !== cases.length
    )
        fail('required case inventory');
    const seen = new Set(),
        receipts = new Set();
    let input = 0,
        output = 0;
    for (const [i, c] of cases.entries()) {
        const r = report.cases[i];
        const tools = {
            cash: 'cash_availability',
            emergency: 'goals',
            nigeria: 'valuation_evidence',
            changes: 'valuation_evidence',
            assessments: 'shariah_history',
        };
        if (r.selected_tool !== (tools[c.claim.split('.')[0]] ?? null))
            fail('tool selection');
        if (
            !Number.isFinite(Date.parse(r.observed_at)) ||
            Date.parse(r.observed_at) < started ||
            Date.parse(r.observed_at) > completed
        )
            fail('case observation time');
        if (
            r.id !== c.id ||
            seen.has(r.id) ||
            r.case_sha256 !== digest(JSON.stringify(c)) ||
            r.input_sha256 !==
                digest(
                    JSON.stringify({
                        QUESTION: c.question,
                        DOCUMENT: c.document,
                    }),
                ) ||
            r.http_status !== 200 ||
            r.http_attempts !== 1 ||
            r.handler_transfers !== 1 ||
            r.native_curl_stats !== true ||
            r.model !== report.model ||
            r.claim !== c.claim ||
            r.mode !== c.mode ||
            r.scope !== c.scope
        )
            fail('case selection or real transport');
        if (
            r.policy_sha256 !== policyDigest ||
            r.evaluation !== true ||
            r.max_input_tokens !== releasePolicy.max_input_tokens ||
            r.max_output_tokens !== releasePolicy.max_output_tokens ||
            r.reasoning_effort !== releasePolicy.reasoning_effort ||
            r.store !== false ||
            !Number.isSafeInteger(r.wire_bytes) ||
            r.wire_bytes < 1 ||
            r.wire_bytes + releasePolicy.wire_overhead_tokens >
                releasePolicy.max_input_tokens ||
            !/^[a-f0-9]{64}$/.test(r.payload_sha256)
        )
            fail('enforced request policy');
        if (
            !/^[a-f0-9]{64}$/.test(r.response_id_sha256) ||
            receipts.has(r.response_id_sha256)
        )
            fail('distinct provider receipts');
        seen.add(r.id);
        receipts.add(r.response_id_sha256);
        if (
            !Number.isSafeInteger(r.usage?.input_tokens) ||
            !Number.isSafeInteger(r.usage?.output_tokens) ||
            r.usage.input_tokens < 1 ||
            r.usage.output_tokens < 1 ||
            r.usage.output_tokens > 512 ||
            r.usage.total_tokens !==
                r.usage.input_tokens + r.usage.output_tokens ||
            r.usage.input_tokens > releasePolicy.max_input_tokens ||
            r.usage.total_tokens >
                releasePolicy.max_input_tokens + releasePolicy.max_output_tokens
        )
            fail('measured usage');
        for (const k of [
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
        ])
            if (r.checks?.[k] !== true) fail('case gate ' + k);
        input += r.usage.input_tokens;
        output += r.usage.output_tokens;
    }
    if (
        report.totals?.input_tokens !== input ||
        report.totals?.output_tokens !== output ||
        report.totals?.cases !== cases.length ||
        report.total_eval_cap_micro_usd !== 2000000 ||
        !Number.isSafeInteger(report.reserved_total_micro_usd) ||
        report.reserved_total_micro_usd > 2000000 ||
        report.reserved_total_micro_usd <
            cases.length * releasePolicy.case_reservation_micro_usd
    )
        fail('totals/budget');
    if (
        !report.limitation?.includes('retained observed receipt') ||
        !report.limitation.includes('not a provider-signed attestation')
    )
        fail('trust limitation');
    return {
        result: 'PASS',
        model: releasePolicy.model,
        policy_sha256: policyDigest,
        max_input_tokens: releasePolicy.max_input_tokens,
        basis_sha256: basis,
        cases: cases.length,
        finance: cases.filter((c) => c.category === 'finance').length,
        adversarial: cases.filter((c) => c.category === 'adversarial').length,
        input_tokens: input,
        output_tokens: output,
        transport: report.transport,
    };
}
if (process.argv[1] === fileURLToPath(import.meta.url)) {
    try {
        console.log(
            JSON.stringify(
                validateLiveReport(
                    JSON.parse(readFileSync(path.join(root, liveReport))),
                    evaluationBasis(),
                    readFileSync(
                        path.join(
                            root,
                            'resources/copilot/evaluation/questions-v1.json',
                        ),
                    ),
                    readFileSync(
                        path.join(root, 'resources/copilot/answer-plan-v1.txt'),
                    ),
                ),
            ),
        );
    } catch (e) {
        console.error(e.message);
        process.exitCode = 2;
    }
}
