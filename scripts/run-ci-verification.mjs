import { mkdirSync, renameSync, existsSync } from 'node:fs';
import { reportFailure, run, testingEnvironment } from './verification.mjs';

try {
    const job = process.argv[2];
    if (!/^(http|[1-8])$/.test(job ?? '') || process.argv.length !== 3)
        throw Error('Supply http or a Pest shard number from 1 to 8.');
    if (!/^[a-f0-9]{32}$/.test(process.env.HWO_RELEASE_RUN_ID ?? ''))
        throw Error('The job requires this workflow run’s verification ID.');
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
    if (job === 'http') {
        mkdirSync('.runtime', { recursive: true });
        run('node', ['scripts/acceptance-http.mjs', '--ci-http'], { env });
    } else {
        mkdirSync('build/ci-shard', { recursive: true });
        run(
            'php',
            [
                'vendor/bin/pest',
                '--ci',
                '--no-tia',
                `--shard=${job}/8`,
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
    }
} catch (error) {
    reportFailure(error);
}
