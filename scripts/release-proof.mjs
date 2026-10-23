import { spawnSync } from 'node:child_process';

export function validateRecoveryProof(receipt, source, runId, tables) {
    if (
        !/^[a-f0-9]{32}$/.test(runId ?? '') ||
        receipt?.producer?.run_id !== runId ||
        !/^[a-f0-9]{64}$/.test(source) ||
        receipt.producer.source_sha256 !== source ||
        receipt.result !== 'PASS' ||
        receipt.tables !== tables.length ||
        receipt.cleanup !==
            'OWNED_DATABASES_DROPPED_AND_PRIVATE_FILES_REMOVED' ||
        receipt.populated_downgrade_refused !== true ||
        receipt.wrong_key_target_unchanged !== true ||
        receipt.restored_sequences_write !== true ||
        receipt.restored_immutability_guard !== true ||
        receipt.deployment_authorized !== false ||
        Object.keys(receipt.table_fingerprints ?? {})
            .sort((a, b) => a.localeCompare(b))
            .join(',') !==
            [...tables].sort((a, b) => a.localeCompare(b)).join(',') ||
        Object.keys(receipt.reader_fingerprints ?? {})
            .sort((a, b) => a.localeCompare(b))
            .join(',') !==
            'cash,imports,market,positions,reservations,shariah' ||
        !Array.isArray(receipt.migrations) ||
        receipt.migrations.length < 1 ||
        new Set(receipt.migrations).size !== receipt.migrations.length
    ) {
        throw new Error(
            'Recovery receipt is missing, stale, incomplete or not produced by this full run',
        );
    }
    for (const row of Object.values(receipt.table_fingerprints)) {
        if (
            !Number.isSafeInteger(row.rows) ||
            row.rows < 0 ||
            !/^[a-f0-9]{64}$/.test(row.sha256)
        )
            throw new Error('Invalid recovery table fingerprint');
    }
    for (const hash of Object.values(receipt.reader_fingerprints)) {
        if (!/^[a-f0-9]{64}$/.test(hash))
            throw new Error('Invalid recovery reader fingerprint');
    }
    // Already finalized receipts must never be rebound to different source/JUnit.
    if (receipt.proof && receipt.proof.source_sha256 !== source)
        throw new Error('Finalized recovery source does not match');
}

export function validateFinalizedProof(receipt, source, junit) {
    if (
        receipt.proof?.source_sha256 !== source ||
        receipt.proof?.junit_sha256 !== junit
    )
        throw new Error('Finalized recovery source/JUnit does not match');
}

export function readReleaseIdentity(directory) {
    const git = (...args) => {
        const result = spawnSync('git', args, {
            cwd: directory,
            encoding: 'utf8',
        });
        if (result.status !== 0) throw new Error('Release Git identity failed');
        return result.stdout.trim();
    };
    const identity = {
        commit: git('rev-parse', 'HEAD'),
        tree: git('rev-parse', 'HEAD^{tree}'),
        dirty: git('status', '--porcelain', '--untracked-files=normal') !== '',
    };
    if (identity.dirty)
        throw new Error(
            'Release finalization requires a clean tracked and untracked checkout',
        );
    return identity;
}
