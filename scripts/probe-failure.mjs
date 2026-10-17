import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import {
    root,
    run,
    reportFailure,
    VerificationFailure,
} from './verification.mjs';

const probe = path.join(root, 'tests/Feature/VerificationFailureProbeTest.php');
let created = false;
const cleanup = () => {
    if (created) {
        unlinkSync(probe);
        created = false;
    }
};
process.on('exit', cleanup);
for (const signal of ['SIGINT', 'SIGTERM'])
    process.on(signal, () => {
        cleanup();
        process.exit(130);
    });

try {
    if (existsSync(probe))
        throw new VerificationFailure(
            'BLOCKED: failure-probe file already exists; preserving it',
        );
    run(path.join(root, 'bin/verify'), ['--phase', 'M0-P2']);
    writeFileSync(
        probe,
        `<?php

namespace Tests\\Feature;

use Tests\\TestCase;

uses(TestCase::class);

test('deliberate failure propagates', function (): void {
    $this->fail('M0-P2 deliberate failure propagation probe');
});
`,
        { flag: 'wx' },
    );
    created = true;
    const result = spawnSync(
        path.join(root, 'bin/verify'),
        ['--phase', 'M0-P2'],
        { cwd: root, stdio: 'inherit' },
    );
    const report = path.join(root, 'build/junit.xml');
    if (
        result.status !== 1 ||
        !existsSync(report) ||
        !readFileSync(report, 'utf8').includes(
            'M0-P2 deliberate failure propagation probe',
        )
    ) {
        throw new VerificationFailure(
            'BLOCKED: the intended Pest failure was not observed through bin/verify',
        );
    }
    console.log(
        'PASS: deliberately failing Pest test caused bin/verify exit 1',
    );
    cleanup();
    run(path.join(root, 'bin/verify'), ['--all']);
    console.log('PASS: restoring the test restored successful verification');
} catch (error) {
    cleanup();
    reportFailure(error);
}
