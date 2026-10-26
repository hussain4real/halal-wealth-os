import { validateLiveReport } from './verify-answer-eval.mjs';
import { evaluationBasis, liveReport } from './answer-eval-basis.mjs';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { sourceIdentity, root } from './verification.mjs';
import {
    readReleaseIdentity,
    validateRecoveryProof,
    validateFinalizedProof,
} from './release-proof.mjs';

const digest = (file) =>
    createHash('sha256').update(readFileSync(file)).digest('hex');
try {
    rmSync('build/release-record.json', { force: true });
    const identity = readReleaseIdentity(root);
    const recovery = JSON.parse(
        readFileSync('build/release-recovery.json', 'utf8'),
    );
    const http = JSON.parse(readFileSync('build/acceptance-http.json', 'utf8'));
    const source = sourceIdentity();
    const junit = digest('build/junit.xml');
    const catalog = JSON.parse(
        readFileSync('app/Support/Release/owner-data-schema.json', 'utf8'),
    );
    validateRecoveryProof(
        recovery,
        source,
        process.env.HWO_RELEASE_RUN_ID,
        catalog.tables.map((table) => table.table),
    );
    if (
        http.result !== 'PASS' ||
        http.source_sha256 !== source ||
        http.junit_sha256 !== junit
    )
        throw new Error('Current HTTP/source/JUnit proof did not reconcile');
    const manifestPath = 'public/build/manifest.json';
    const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
    const assets = new Set();
    for (const entry of Object.values(manifest)) {
        assets.add(entry.file);
        for (const file of entry.css ?? []) assets.add(file);
        for (const file of entry.assets ?? []) assets.add(file);
    }
    const hashes = {};
    for (const asset of [...assets].sort((a, b) => a.localeCompare(b))) {
        if (
            !asset.startsWith('assets/') ||
            asset.includes('..') ||
            !existsSync(`public/build/${asset}`)
        )
            throw new Error('Release build asset is missing or unsafe');
        hashes[asset] = digest(`public/build/${asset}`);
    }
    if (assets.size < 1) throw new Error('Release build is empty');
    const proof = { source_sha256: source, junit_sha256: junit };
    if (recovery.proof) validateFinalizedProof(recovery, source, junit);
    recovery.proof = proof;
    writeFileSync(
        'build/release-recovery.json',
        `${JSON.stringify(recovery, null, 2)}\n`,
    );
    const record = {
        result: 'PASS',
        phase: 'M7-P3',
        live_evaluation: validateLiveReport(
            JSON.parse(readFileSync(liveReport)),
            evaluationBasis(),
            readFileSync('resources/copilot/evaluation/questions-v1.json'),
            readFileSync('resources/copilot/answer-plan-v2.txt'),
        ),
        live_evaluation_sha256: digest(liveReport),
        ...proof,
        ...identity,
        build_manifest_sha256: digest(manifestPath),
        assets: hashes,
        lock_sha256: {
            composer: digest('composer.lock'),
            npm: digest('package-lock.json'),
        },
        migrations: recovery.migrations,
        configuration_sha256: Object.fromEntries(
            [
                'config/copilot.php',
                'config/market_data.php',
                'config/drive_export.php',
                'config/operations.php',
                'config/mail.php',
            ].map((file) => [file, digest(file)]),
        ),
        configuration_scope:
            'Source defaults only; deployment values and live entitlement need owner review',
        operational_rollback: {
            result: recovery.operational_rollback.result,
            strategy: recovery.operational_rollback.strategy,
            code_downgrade_rehearsed: false,
        },
        recovery_sha256: digest('build/release-recovery.json'),
        deployment_authorized: false,
        owner_phase_acceptance: 'PENDING',
        limitations: [
            'Synthetic local/CI rehearsal only',
            'Production host, retention, RPO/RTO, mail delivery and key custody require owner signoff',
            'Populated downgrade is unsupported; restore matching code and backup or use reviewed forward correction',
        ],
    };
    writeFileSync(
        'build/release-record.json',
        `${JSON.stringify(record, null, 2)}\n`,
    );
    console.log(
        'PASS release source/build/migration identity and actual encrypted recovery proof',
    );
} catch (error) {
    console.error(error.message);
    process.exitCode = 2;
}
