import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { evaluationBasis, liveReport } from './answer-eval-basis.mjs';
import { validateLiveReport } from './verify-answer-eval.mjs';

const evaluatedCommit = '2aebc7e149575f305dd4b91fa137f24f58dfe5c6';
const approvalFile = 'docs/halal-wealth-os/runs/ci-sharding-approval.json';
// PR #31's four documentation files, including the formatting correction.
const approvedDocumentationCommit = '18601331bac1f4656a311489f3880430da5c3c86';
const documentationExceptionPaths = new Set([
    approvalFile,
    'scripts/ci-evaluation-evidence.mjs',
]);
const approvedPaths = new Set([
    '.github/workflows/tests.yml',
    'docs/halal-wealth-os/VALIDATION.md',
    approvalFile,
    'scripts/acceptance-http.mjs',
    'scripts/ci-evaluation-evidence.mjs',
    'scripts/merge-ci-shards.php',
    'scripts/verification-mode.mjs',
    'scripts/verify-acceptance.php',
    'scripts/run-ci-verification.mjs',
    'scripts/verify-release.mjs',
    'scripts/verify.mjs',
    'tests/.pest/shards.json',
    'tests/Feature/CopilotAnswerTest.php',
    'tests/Feature/MarketRefreshTest.php',
]);

export function verifyCiEvaluation() {
    const receipt = readFileSync(liveReport);
    const report = JSON.parse(receipt);
    const dataset = readFileSync(
        'resources/copilot/evaluation/questions-v1.json',
    );
    const prompt = readFileSync('resources/copilot/answer-plan-v2.txt');
    const basis = evaluationBasis();
    if (basis === report.basis_sha256)
        return validateLiveReport(report, basis, dataset, prompt);

    const git = (...args) => execFileSync('git', args);
    const fail = () => {
        throw Error(
            'BLOCKED: the approved CI-only evaluation exception does not cover this source.',
        );
    };

    // Require a clean checkout of the approved source diff and a retained receipt
    // identical to the one at the reviewed head. Never generate a new live receipt.
    if (git('status', '--porcelain', '--untracked-files=normal').length) fail();
    git('merge-base', '--is-ancestor', evaluatedCommit, 'HEAD');
    const changedPaths = (commit) =>
        git('diff', '--no-renames', '--name-only', '-z', commit, 'HEAD')
            .toString()
            .split('\0')
            .filter(Boolean);
    const approval = JSON.parse(readFileSync(approvalFile));
    if (
        approval.evaluated_commit !== evaluatedCommit ||
        approval.evaluated_basis_sha256 !== report.basis_sha256 ||
        !receipt.equals(git('show', `${evaluatedCommit}:${liveReport}`))
    )
        fail();

    const shardingOnly =
        approval.approved_ci_basis_sha256 === basis &&
        changedPaths(evaluatedCommit).every((file) => approvedPaths.has(file));
    if (!shardingOnly) {
        // Pin the documentation bytes to the reviewed commit. Only the verifier
        // and its approval record may differ from that exact source tree.
        const documentation = approval.pr31_documentation;
        if (
            documentation?.reviewed_commit !== approvedDocumentationCommit ||
            documentation.approved_ci_basis_sha256 !== basis
        )
            fail();
        git('merge-base', '--is-ancestor', approvedDocumentationCommit, 'HEAD');
        const paths = changedPaths(approvedDocumentationCommit);
        if (
            paths.length !== documentationExceptionPaths.size ||
            !paths.every((file) => documentationExceptionPaths.has(file))
        )
            fail();
    }
    return {
        ...validateLiveReport(
            report,
            approval.evaluated_basis_sha256,
            dataset,
            prompt,
        ),
        scope: 'APPROVED_CI_CARRY_FORWARD',
        evaluated_commit: evaluatedCommit,
        evaluated_basis_sha256: approval.evaluated_basis_sha256,
    };
}
