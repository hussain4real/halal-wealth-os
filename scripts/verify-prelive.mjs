import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { sourceIdentity } from './verification.mjs';
import { evaluationBasis, liveReport } from './answer-eval-basis.mjs';
import { validateRecoveryProof } from './release-proof.mjs';
const digest = (file) =>
    createHash('sha256').update(readFileSync(file)).digest('hex');
try {
    if (
        existsSync('build/release-record.json') ||
        existsSync('build/acceptance-map-report.json')
    )
        throw Error('Preparation cannot reuse final release artifacts');
    const source = sourceIdentity();
    const recovery = JSON.parse(readFileSync('build/release-recovery.json'));
    const catalog = JSON.parse(
        readFileSync('app/Support/Release/owner-data-schema.json'),
    );
    validateRecoveryProof(
        recovery,
        source,
        process.env.HWO_RELEASE_RUN_ID,
        catalog.tables.map((t) => t.table),
    );
    const http = JSON.parse(readFileSync('build/acceptance-http.json'));
    if (
        http.phase !== 'M7-P3' ||
        http.result !== 'PASS' ||
        http.source_sha256 !== source ||
        http.junit_sha256 !== digest('build/junit.xml')
    )
        throw Error(
            'Current deterministic HTTP phase/source/JUnit evidence required',
        );
    const mapped = spawnSync('php', ['scripts/configured-acceptance.php'], {
        encoding: 'utf8',
        env: process.env,
    });
    if (mapped.status !== 0)
        throw Error(mapped.stderr || 'Configured mapping failed');
    const receipt = JSON.parse(readFileSync(liveReport));
    if (receipt.result !== 'PENDING')
        throw Error('Preparation is only for the current pending live gate');
    const report = {
        result: 'DETERMINISTIC_CHECKS_PASS_LIVE_GATE_PENDING',
        phase: 'M7-P3',
        source_sha256: source,
        basis_sha256: evaluationBasis(),
        junit_sha256: digest('build/junit.xml'),
        recovery_sha256: digest('build/release-recovery.json'),
        http_sha256: digest('build/acceptance-http.json'),
        configured_map: JSON.parse(mapped.stdout),
        live_evaluation: 'PENDING',
        release_ready: false,
        deployment_authorized: false,
        owner_acceptance: 'PENDING',
    };
    writeFileSync(
        'build/prelive-readiness.json',
        JSON.stringify(report, null, 2) + '\n',
    );
    console.log(report.result);
} catch (error) {
    console.error(error.message);
    process.exitCode = 2;
}
