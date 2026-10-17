import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { frontendSnapshot } from './tia-frontend.mjs';
import path from 'node:path';
import {
    VerificationFailure,
    reportFailure,
    root,
    run,
    testingEnvironment,
} from './verification.mjs';

try {
    const args = process.argv.slice(2);
    const record = args.length === 1 && args[0] === '--record';
    if (args.length && !record)
        throw new VerificationFailure('Usage: ./bin/test-impact [--record]');
    for (const file of [
        'composer.lock',
        'package-lock.json',
        'vendor/bin/pest',
        'tests/Pest.php',
        'node_modules/vite/package.json',
    ]) {
        if (!existsSync(path.join(root, file)))
            throw new VerificationFailure(`BLOCKED: missing ${file}`);
    }
    const env = testingEnvironment();
    run('php', ['scripts/verify-platform.php'], { env });
    const discovered = run(
        'php',
        ['vendor/bin/pest', '--ci', '--no-tia', '--list-tests'],
        { env, stdio: 'pipe', encoding: 'utf8' },
    ).stdout;
    for (const suite of ['Feature', 'Unit']) {
        if (
            !discovered
                .split('\n')
                .some((line) =>
                    line.trimStart().startsWith(`- P\\Tests\\${suite}\\`),
                )
        )
            throw new VerificationFailure(
                `BLOCKED: no Pest ${suite} tests discovered`,
            );
    }
    const baseline = path.join(root, '.runtime/pest/tia/graph.json');
    const hasCompleteBaseline = () => {
        try {
            const graph = JSON.parse(readFileSync(baseline, 'utf8'));
            return Object.values(graph.baselines ?? {}).some(
                (entry) =>
                    entry.complete === true &&
                    Object.keys(entry.results ?? {}).length > 0,
            );
        } catch {
            return false;
        }
    };
    const frontendState = path.join(root, '.runtime/pest/tia/frontend.json');
    const branch = run('git', ['branch', '--show-current'], {
        stdio: 'pipe',
        encoding: 'utf8',
    }).stdout.trim();
    const snapshot = frontendSnapshot(root);
    let previous;
    try {
        previous = JSON.parse(readFileSync(frontendState, 'utf8'));
    } catch {
        previous = null;
    }
    if (
        !record &&
        (!hasCompleteBaseline() ||
            previous?.branch !== branch ||
            typeof previous?.snapshot !== 'string')
    )
        throw new VerificationFailure(
            'BLOCKED: missing or invalid TIA baseline; run composer test:impact:record first. No impacted-test success is claimed.',
        );
    // Pest 5.3.2's resolver cannot transform Vue SFCs. A shared frontend
    // change must rebuild the entire baseline, never replay unproven edges.
    const fresh = record || previous.snapshot !== snapshot;
    if (fresh && !record)
        console.log(
            'Shared frontend/configuration changed: recording the complete TIA suite; no selective JavaScript graph is claimed.',
        );
    run('php', ['scripts/verify-database.php'], { env });
    run(
        'php',
        [
            'vendor/bin/pest',
            '--tia',
            '--fail-on-empty-test-suite',
            ...(fresh ? ['--fresh'] : []),
        ],
        { env },
    );
    if (!hasCompleteBaseline())
        throw new VerificationFailure(
            'BLOCKED: TIA did not retain a complete baseline',
        );
    writeFileSync(frontendState, JSON.stringify({ branch, snapshot }) + '\n');
    console.log(
        'PASS local Pest TIA run; cached/replayed results are not full-suite verification.',
    );
    console.log(
        'Full gate: ./bin/verify --all (Pest --ci --no-tia plus real coverage).',
    );
} catch (error) {
    reportFailure(error);
}
