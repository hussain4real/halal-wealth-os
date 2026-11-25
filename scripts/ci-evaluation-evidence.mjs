import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { evaluationBasis, liveReport } from './answer-eval-basis.mjs';
import { validateLiveReport } from './verify-answer-eval.mjs';

const evaluatedCommit = '2aebc7e149575f305dd4b91fa137f24f58dfe5c6';
const approvalFile = 'docs/halal-wealth-os/runs/ci-sharding-approval.json';
// PR #31's approved docs and exact WebAuthn 5.3.13 lockfile change.
const pr31Base = '9309f5277a205704af50faae0083e8336b3b78d1';
const approvedPr31Commit = '604a82393291b419ef5b72723a5e794ff26ec04f';
const approvedPr31TreeSha256 =
    'aeb4123ed1081309f2636a3279d98310b10e27605eb8850ce3063b6eca8dc3f4';
const pr31Files = [
    'AGENTS.md',
    'composer.lock',
    'docs/agents/domain.md',
    'docs/agents/issue-tracker.md',
    'docs/agents/triage-labels.md',
];
const pr31ExceptionPaths = new Set([
    ...pr31Files,
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
        // Pin the approved paths, modes and blob identities independently of
        // feature-branch ancestry so the reviewed tree also works after squashing.
        const pr31 = approval.pr31_webauthn;
        if (
            pr31?.reviewed_commit !== approvedPr31Commit ||
            pr31.approved_ci_basis_sha256 !== basis
        )
            fail();
        git('merge-base', '--is-ancestor', pr31Base, 'HEAD');
        const paths = changedPaths(pr31Base);
        const treeSha256 = createHash('sha256')
            .update(git('ls-tree', '-z', 'HEAD', '--', ...pr31Files))
            .digest('hex');
        if (
            paths.length !== pr31ExceptionPaths.size ||
            !paths.every((file) => pr31ExceptionPaths.has(file)) ||
            treeSha256 !== approvedPr31TreeSha256
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
