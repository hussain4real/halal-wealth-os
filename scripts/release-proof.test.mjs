import assert from 'node:assert/strict';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import {
    readReleaseIdentity,
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
        imports: source,
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
        (r) => {
            delete r.reader_fingerprints.imports;
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

await test('actual Git release identity refuses tracked and untracked changes', () => {
    const directory = mkdtempSync(path.join(tmpdir(), 'hwo-release-identity-'));
    const git = (...args) => {
        const result = spawnSync('git', args, {
            cwd: directory,
            encoding: 'utf8',
            env: {
                ...process.env,
                GIT_AUTHOR_NAME: 'hussain4real',
                GIT_AUTHOR_EMAIL:
                    '45605752+hussain4real@users.noreply.github.com',
                GIT_COMMITTER_NAME: 'hussain4real',
                GIT_COMMITTER_EMAIL:
                    '45605752+hussain4real@users.noreply.github.com',
            },
        });
        assert.equal(result.status, 0, result.stderr);
        return result.stdout.trim();
    };
    try {
        git('init', '--quiet');
        writeFileSync(path.join(directory, 'source.txt'), 'reviewed source\n');
        git('add', '--', 'source.txt');
        git(
            '-c',
            'commit.gpgsign=false',
            'commit',
            '--quiet',
            '-m',
            'synthetic release identity',
        );
        assert.deepEqual(readReleaseIdentity(directory), {
            commit: git('rev-parse', 'HEAD'),
            tree: git('rev-parse', 'HEAD^{tree}'),
            dirty: false,
        });
        writeFileSync(
            path.join(directory, 'source.txt'),
            'uncommitted source\n',
        );
        assert.throws(
            () => readReleaseIdentity(directory),
            /clean tracked and untracked/,
        );
        writeFileSync(path.join(directory, 'source.txt'), 'reviewed source\n');
        writeFileSync(
            path.join(directory, 'untracked-source.txt'),
            'untracked source\n',
        );
        assert.throws(
            () => readReleaseIdentity(directory),
            /clean tracked and untracked/,
        );
        rmSync(path.join(directory, 'untracked-source.txt'));
        assert.equal(readReleaseIdentity(directory).dirty, false);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});
