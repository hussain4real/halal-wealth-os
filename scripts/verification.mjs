import { randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export const root = path.resolve(fileURLToPath(new URL('..', import.meta.url)));
export const disposableDatabase = 'halal_wealth_os_test';

export class VerificationFailure extends Error {
    constructor(message, status = 2) {
        super(message);
        this.status = status;
    }
}

export function run(command, args, options = {}) {
    console.log(`CHECK ${command} ${args.join(' ')}`);
    const result = spawnSync(command, args, {
        cwd: root,
        env: process.env,
        stdio: 'inherit',
        ...options,
    });
    if (result.error || result.status !== 0) {
        throw new VerificationFailure(
            `${command} failed${result.error ? `: ${result.error.message}` : ''}`,
            result.status || 2,
        );
    }
    return result;
}

export function testingEnvironment() {
    if (process.env.HWO_DISPOSABLE_DATABASE !== disposableDatabase) {
        throw new VerificationFailure(
            `BLOCKED: explicitly set HWO_DISPOSABLE_DATABASE=${disposableDatabase}; migrations are destructive and restricted to that disposable database.`,
        );
    }
    return {
        ...process.env,
        APP_ENV: 'testing',
        APP_DEBUG: 'false',
        APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
        APP_URL: 'http://localhost:8000',
        DB_CONNECTION: 'pgsql',
        DB_HOST: process.env.HWO_TEST_HOST || '/var/run/postgresql',
        DB_PORT: process.env.HWO_TEST_PORT || '5432',
        DB_DATABASE: disposableDatabase,
        DB_USERNAME: process.env.HWO_TEST_USER || 'hwo',
        DB_PASSWORD: process.env.HWO_TEST_PASSWORD || '',
        DB_URL: '',
        DATABASE_URL: '',
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'array',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'array',
        XDEBUG_MODE: 'coverage',
        LARAVEL_INSTALLER_DEFER_HOOKS: 'true',
    };
}

export function migrationSmoke(env) {
    // The guard checks the booted application's actual connection, not just
    // shell variables, before any destructive command is allowed to execute.
    run('php', ['scripts/verify-database.php'], { env });
    run(
        'php',
        [
            'artisan',
            'migrate:fresh',
            '--database=pgsql',
            '--force',
            '--no-interaction',
        ],
        { env },
    );
    run(
        'php',
        [
            'artisan',
            'migrate:rollback',
            '--database=pgsql',
            '--step=1000',
            '--force',
            '--no-interaction',
        ],
        { env },
    );
    run(
        'php',
        [
            'artisan',
            'migrate',
            '--database=pgsql',
            '--force',
            '--no-interaction',
        ],
        { env },
    );
    run(
        'php',
        ['artisan', 'migrate:status', '--database=pgsql', '--no-interaction'],
        { env },
    );
}

export function manifest() {
    return JSON.parse(readFileSync(path.join(root, 'composer.json'), 'utf8'));
}

export function reportFailure(error) {
    console.error(error.message);
    process.exitCode = error.status || 2;
}
