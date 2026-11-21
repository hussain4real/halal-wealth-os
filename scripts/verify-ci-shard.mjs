import { mkdirSync, renameSync, existsSync } from 'node:fs';
import { reportFailure, run, testingEnvironment } from './verification.mjs';

try {
    const shard = process.argv[2];
    if (!/^[1-8]$/.test(shard ?? '') || process.argv.length !== 3)
        throw Error('Supply one Pest shard number from 1 to 8.');
    if (!/^[a-f0-9]{32}$/.test(process.env.HWO_RELEASE_RUN_ID ?? ''))
        throw Error('The shard requires this workflow run’s verification ID.');
    const env = testingEnvironment();
    run('php', ['scripts/verify-platform.php'], { env });
    run('php', ['scripts/verify-database.php'], { env });
    run(
        'php',
        ['artisan', 'wayfinder:generate', '--with-form', '--no-interaction'],
        { env },
    );
    run('php', ['artisan', 'filament:assets', '--no-interaction'], { env });
    run('npm', ['run', 'build'], { env });
    mkdirSync('build/ci-shard', { recursive: true });
    run(
        'php',
        [
            'vendor/bin/pest',
            '--ci',
            '--no-tia',
            `--shard=${shard}/8`,
            '--fail-on-empty-test-suite',
            '--log-junit',
            'build/ci-shard/junit.xml',
            '--coverage-php',
            'build/ci-shard/coverage.php',
        ],
        { env },
    );
    if (existsSync('build/release-recovery.json'))
        renameSync(
            'build/release-recovery.json',
            'build/ci-shard/release-recovery.json',
        );
} catch (error) {
    reportFailure(error);
}
