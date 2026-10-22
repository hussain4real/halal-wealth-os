import assert from 'node:assert/strict';
import test from 'node:test';
import {
    validateRecoveryProof,
    validateFinalizedProof,
} from './release-proof.mjs';

const source = 'a'.repeat(64);
const run = 'b'.repeat(32);
const junit = 'c'.repeat(64);
const valid = () => ({
    result: 'PASS',
    producer: { source_sha256: source, run_id: run },
    tables: 1,
    table_fingerprints: { accounts: { rows: 7, sha256: 'd'.repeat(64) } },
    reader_fingerprints: {
        cash: source,
        positions: source,
        reservations: source,
        shariah: source,
    },
    migrations: ['synthetic_migration'],
    cleanup: 'OWNED_DATABASES_DROPPED_AND_PRIVATE_FILES_REMOVED',
    populated_downgrade_refused: true,
    wrong_key_target_unchanged: true,
    restored_sequences_write: true,
    restored_immutability_guard: true,
    deployment_authorized: false,
});

await test('actual run identity is required and rejected receipts are never relabeled or mutated', () => {
    validateRecoveryProof(valid(), source, run, ['accounts']);
    for (const change of [
        (r) => {
            r.producer.source_sha256 = 'e'.repeat(64);
        },
        (r) => {
            delete r.producer.run_id;
        },
        (r) => {
            r.producer.run_id = 'FOCUSED_ONLY';
        },
        (r) => {
            r.producer.run_id = 'f'.repeat(32);
        },
        (r) => {
            r.result = 'FAIL';
        },
        (r) => {
            r.restored_immutability_guard = false;
        },
        (r) => {
            delete r.table_fingerprints.accounts;
        },
    ]) {
        const receipt = valid();
        change(receipt);
        const bytes = JSON.stringify(receipt);
        assert.throws(() =>
            validateRecoveryProof(receipt, source, run, ['accounts']),
        );
        assert.equal(JSON.stringify(receipt), bytes);
    }
    assert.throws(() =>
        validateRecoveryProof(valid(), source, undefined, ['accounts']),
    );
});

await test('a finalized receipt refuses a different JUnit or source without mutation', () => {
    const receipt = {
        ...valid(),
        proof: { source_sha256: source, junit_sha256: junit },
    };
    validateFinalizedProof(receipt, source, junit);
    const bytes = JSON.stringify(receipt);
    assert.throws(() =>
        validateFinalizedProof(receipt, source, 'f'.repeat(64)),
    );
    assert.throws(() => validateFinalizedProof(receipt, 'f'.repeat(64), junit));
    assert.equal(JSON.stringify(receipt), bytes);
});
