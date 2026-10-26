import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import {
    mkdtempSync,
    mkdirSync,
    writeFileSync,
    rmSync,
    unlinkSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { frontendSnapshot } from './tia-frontend.mjs';
import test from 'node:test';
import {
    root,
    run,
    testingEnvironment,
    VerificationFailure,
} from './verification.mjs';

await test('unknown and unimplemented phases never report a passing result', () => {
    for (const phase of ['M99-P99', 'M7-P4']) {
        const result = spawnSync(
            path.join(root, 'bin/verify'),
            ['--phase', phase],
            { encoding: 'utf8' },
        );
        assert.equal(result.status, 2);
        assert.match(result.stderr, /Unknown phase/);
    }
});

await test('destructive smoke preparation requires the exact explicit disposable marker', () => {
    const previous = process.env.HWO_DISPOSABLE_DATABASE;
    try {
        process.env.HWO_DISPOSABLE_DATABASE = 'halal_wealth_os_dev';
        assert.throws(
            testingEnvironment,
            /explicitly set HWO_DISPOSABLE_DATABASE/,
        );
        delete process.env.HWO_DISPOSABLE_DATABASE;
        assert.throws(testingEnvironment, VerificationFailure);
    } finally {
        if (previous === undefined) delete process.env.HWO_DISPOSABLE_DATABASE;
        else process.env.HWO_DISPOSABLE_DATABASE = previous;
    }
});

await test('verification command failures preserve the actual child exit code', () => {
    assert.throws(
        () =>
            run(process.execPath, ['-e', 'process.exit(17)'], {
                stdio: 'ignore',
            }),
        (error) => error instanceof VerificationFailure && error.status === 17,
    );
});

await test('shared frontend edits, additions and deletions force a fresh baseline while page edits retain Pest selection', () => {
    const fixture = mkdtempSync(path.join(tmpdir(), 'hwo-tia-'));
    try {
        mkdirSync(path.join(fixture, 'resources/js/pages'), {
            recursive: true,
        });
        mkdirSync(path.join(fixture, 'resources/js/components'));
        for (const file of [
            'vite.config.ts',
            'tsconfig.json',
            'package.json',
            'package-lock.json',
        ])
            writeFileSync(path.join(fixture, file), '{}');
        const shared = path.join(fixture, 'resources/js/components/Shared.vue');
        const page = path.join(fixture, 'resources/js/pages/Page.vue');
        writeFileSync(shared, '<template>one</template>');
        writeFileSync(page, '<template>page</template>');
        const original = frontendSnapshot(fixture);
        assert.equal(frontendSnapshot(fixture), original);
        writeFileSync(page, '<template>changed page</template>');
        assert.equal(frontendSnapshot(fixture), original);
        writeFileSync(shared, '<template>two</template>');
        const edited = frontendSnapshot(fixture);
        assert.notEqual(edited, original);
        const added = path.join(fixture, 'resources/js/components/New.vue');
        writeFileSync(added, '<template>new</template>');
        const withNew = frontendSnapshot(fixture);
        assert.notEqual(withNew, edited);
        unlinkSync(added);
        assert.equal(frontendSnapshot(fixture), edited);
        unlinkSync(shared);
        assert.notEqual(frontendSnapshot(fixture), edited);
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});

await test('prelive preparation is explicit and cannot modify ordinary release invocation', async () => {
    const { verificationMode } = await import('./verification-mode.mjs');
    assert.deepEqual(verificationMode(['--all']), {
        all: true,
        phase: null,
        prelive: false,
    });
    assert.deepEqual(verificationMode(['--phase', 'M7-P3']), {
        all: false,
        phase: 'M7-P3',
        prelive: false,
    });
    assert.equal(verificationMode(['--prelive', 'M7-P3']).prelive, true);
    for (const args of [
        ['--all', '--prelive', 'M7-P3'],
        ['--prelive', 'M7-P2'],
        ['--phase', 'M7-P3', '--prelive'],
        ['--allow-pending'],
    ])
        assert.throws(() => verificationMode(args), /Usage/);
});
