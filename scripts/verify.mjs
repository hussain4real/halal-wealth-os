import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import {
    VerificationFailure,
    manifest,
    migrationSmoke,
    reportFailure,
    root,
    run,
    testingEnvironment,
} from './verification.mjs';

try {
    const args = process.argv.slice(2);
    const all = args.length === 1 && args[0] === '--all';
    const phase = args.length === 2 && args[0] === '--phase' ? args[1] : null;
    if (!all && !phase) {
        throw new VerificationFailure(
            'Usage: ./bin/verify --phase Mx-Py | --all',
        );
    }
    const roadmap = JSON.parse(
        readFileSync(
            path.join(root, 'docs/halal-wealth-os/ROADMAP.json'),
            'utf8',
        ),
    );
    if (phase && !roadmap.phases.some((item) => item.id === phase)) {
        throw new VerificationFailure(`Unknown phase: ${phase}`);
    }
    if (
        phase &&
        ![
            'M0-P1',
            'M0-P2',
            'M0-P3',
            'M1-P1',
            'M1-P2',
            'M1-P3',
            'M2-P1',
            'M2-P2',
            'M2-P3',
        ].includes(phase)
    ) {
        throw new VerificationFailure(
            `NOT_APPLICABLE: ${phase} has no implemented verification suite. No passing result is claimed.`,
        );
    }

    run('node', ['scripts/check-planning.mjs']);
    run('node', ['--test', 'scripts/planning-state.test.mjs']);
    run('git', ['diff', '--check']);
    if (phase === 'M0-P1') {
        console.log('PASS M0-P1 documentation checks');
    } else {
        run('node', [
            '--test',
            'scripts/verification.test.mjs',
            'scripts/currency-display.test.mjs',
            'scripts/planning-identity.test.mjs',
            'scripts/receipt-picker.test.mjs',
            'scripts/planning-match-draft.test.mjs',
            'scripts/goal-request-draft.test.mjs',
            'scripts/cash-settings-draft.test.mjs',
        ]);
        const missing = [];
        for (const command of ['php', 'composer', 'node', 'npm', 'psql']) {
            const result = spawnSync(command, ['--version'], {
                cwd: root,
                stdio: 'ignore',
            });
            if (result.error || result.status !== 0)
                missing.push(`tool: ${command}`);
        }
        for (const file of [
            'composer.lock',
            'package-lock.json',
            'vendor/autoload.php',
            'vendor/bin/pest',
            'node_modules/.bin/vp',
        ]) {
            if (!existsSync(path.join(root, file)))
                missing.push(`required file: ${file}`);
        }
        if (!missing.includes('tool: php')) {
            const php = spawnSync('php', ['scripts/verify-platform.php'], {
                cwd: root,
                env: { ...process.env, XDEBUG_MODE: 'coverage' },
                encoding: 'utf8',
            });
            if (php.status !== 0)
                missing.push(
                    php.stderr.trim() || 'PHP platform/coverage check failed',
                );
        }
        if (missing.length)
            throw new VerificationFailure(`BLOCKED:\n${missing.join('\n')}`);
        const env = testingEnvironment();
        mkdirSync(path.join(root, 'build'), { recursive: true });
        for (const file of ['junit.xml', 'coverage.xml'])
            rmSync(path.join(root, 'build', file), { force: true });
        run('composer', ['validate', '--strict'], { env });
        run('composer', ['check-platform-reqs'], { env });
        run('npm', ['ls', '--all'], { env });
        run('composer', ['audit', '--locked', '--no-interaction'], { env });
        run(
            'npm',
            [
                'audit',
                '--audit-level=high',
                '--cache',
                path.join(root, '.runtime/npm-cache'),
            ],
            { env },
        );
        migrationSmoke(env);
        run(
            'php',
            [
                'artisan',
                'wayfinder:generate',
                '--with-form',
                '--no-interaction',
            ],
            { env },
        );
        run('php', ['artisan', 'filament:assets', '--no-interaction'], { env });
        run('npm', ['run', 'check'], { env });
        run('npm', ['run', 'types:check'], { env });
        run('npm', ['run', 'build'], { env });
        const discovered = run(
            'php',
            ['vendor/bin/pest', '--ci', '--no-tia', '--list-tests'],
            {
                env,
                encoding: 'utf8',
                stdio: 'pipe',
            },
        );
        console.log(discovered.stdout);
        for (const suite of ['Feature', 'Unit']) {
            const namespace = ['Tests', suite, ''].join(
                String.fromCharCode(92),
            );
            if (
                !discovered.stdout
                    .split('\n')
                    .some(
                        (line) =>
                            line.trimStart().startsWith(`- ${namespace}`) ||
                            line
                                .trimStart()
                                .startsWith(
                                    `- P${String.fromCharCode(92)}${namespace}`,
                                ),
                    )
            ) {
                throw new VerificationFailure(
                    `BLOCKED: zero required discovered tests in ${suite}`,
                );
            }
        }
        const scripts = manifest().scripts;
        for (const required of [
            'IdentityBoundaryTest',
            'PrivateOnboardingTest',
            'SessionAuditTest',
            'MoneyPrimitivesTest',
            'AccountLifecycleTest',
            'PostingServiceTest',
            'PostingConcurrencyTest',
            'ManualFxTest',
            'FxConcurrencyTest',
            'LedgerHttpTest',
            'FxMathTest',
            'ReconciliationTest',
            'BudgetTest',
            'BudgetReadBoundaryTest',
            'BudgetHttpTest',
            'BudgetConcurrencyTest',
            'BudgetCalendarTest',
            'GoalTest',
            'GoalHttpTest',
            'GoalConcurrencyTest',
            'GoalReadBoundaryTest',
            'GoalProjectionTest',
            'CashAvailabilityTest',
            'CashAvailabilityHttpTest',
            'CashAvailabilityReadTest',
            'CashAvailabilityConcurrencyTest',
            'AvailabilityMathTest',
        ]) {
            if (!discovered.stdout.includes(required)) {
                throw new VerificationFailure(
                    `BLOCKED: missing implemented required suite ${required}`,
                );
            }
        }
        if (
            !scripts['test:coverage']?.some((entry) =>
                entry.includes('artisan test'),
            )
        ) {
            throw new VerificationFailure(
                'BLOCKED: manifest has no real starter test:coverage command',
            );
        }
        run('composer', ['run', 'test:coverage'], { env });
        run('php', ['scripts/verify-reports.php'], { env });
        console.log(
            'PASS M0-P2, M0-P3, M1-P1, M1-P2, M1-P3, M2-P1, M2-P2 and M2-P3 implemented baseline',
        );
        console.log(
            'NOT_APPLICABLE: future domain suites, integrations, live AI and deployment.',
        );
    }
} catch (error) {
    reportFailure(error);
}
