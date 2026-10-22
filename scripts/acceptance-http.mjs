import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes, createHash } from 'node:crypto';
import {
    existsSync,
    mkdtempSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    writeFileSync,
} from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { performance } from 'node:perf_hooks';
import { root, testingEnvironment, sourceIdentity } from './verification.mjs';

const directory = mkdtempSync(path.join(root, '.runtime/acceptance-'));
const metricsFile = path.join(directory, 'requests.jsonl');
const env = {
    ...testingEnvironment(),
    APP_KEY:
        process.env.APP_KEY || `base64:${randomBytes(32).toString('base64')}`,
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
    LARAVEL_STORAGE_PATH: path.join(directory, 'storage'),
    HWO_ACCEPTANCE_METRICS: metricsFile,
    HWO_ACCEPTANCE_PASSWORD: `Synthetic-initial-${randomBytes(16).toString('hex')}`,
    HWO_ACCEPTANCE_NEW_PASSWORD: `Synthetic-recovered-${randomBytes(16).toString('hex')}`,
    XDEBUG_MODE: 'off',
};
delete env.APP_RUNNING_IN_CONSOLE;
for (const name of [
    'framework/sessions',
    'framework/cache/data',
    'framework/views',
    'logs',
]) {
    mkdirSync(path.join(env.LARAVEL_STORAGE_PATH, name), {
        recursive: true,
        mode: 0o700,
    });
}
function php(args, json = false) {
    const child = spawnSync('php', ['-d', 'pcov.enabled=0', ...args], {
        cwd: root,
        env,
        encoding: 'utf8',
        maxBuffer: 32 * 1024 * 1024,
    });
    if (child.status !== 0 || child.error) {
        writeFileSync(
            path.join(directory, 'failed-php.log'),
            child.stdout + child.stderr,
        );
        throw new Error(`Acceptance PHP command failed: ${args[0]}`);
    }
    if (!json) return child.stdout;
    try {
        return JSON.parse(child.stdout);
    } catch {
        writeFileSync(
            path.join(directory, 'failed-php.log'),
            child.stdout + child.stderr,
        );
        throw new Error(`Invalid acceptance PHP result: ${args.join(' ')}`);
    }
}
const fixture = (...args) =>
    php(['scripts/acceptance-fixture.php', ...args], true);
function reset() {
    php(['scripts/verify-database.php']);
    php(['artisan', 'migrate:fresh', '--force', '--no-interaction']);
    return fixture('prepare');
}
const source = sourceIdentity();
const port = await new Promise((resolve, reject) => {
    const socket = net.createServer();
    socket.once('error', reject);
    socket.listen(0, '127.0.0.1', () => {
        const value = socket.address().port;
        socket.close(() => resolve(value));
    });
});
const base = `http://127.0.0.1:${port}`;
env.APP_URL = base;
const secrets = new Set([
    env.HWO_ACCEPTANCE_PASSWORD,
    env.HWO_ACCEPTANCE_NEW_PASSWORD,
]);
function storedFiles(directory) {
    try {
        return readdirSync(directory, { withFileTypes: true })
            .flatMap((entry) =>
                entry.isDirectory()
                    ? storedFiles(path.join(directory, entry.name))
                    : [path.join(directory, entry.name)],
            )
            .sort((a, b) => a.localeCompare(b));
    } catch (error) {
        if (error.code === 'ENOENT') return [];
        throw error;
    }
}
let counter = 0;
const key = () => `synthetic-quality-${++counter}`;
function page(body) {
    const script = body.match(
        /<script\b(?=[^>]*\btype="application\/json")(?=[^>]*\bdata-page=)[^>]*>([\s\S]*?)<\/script>/,
    );
    if (script) return JSON.parse(script[1]);
    const attribute = body.match(/data-page="([^"]*)"/);
    assert(attribute, 'Dashboard must contain the real Inertia page');
    const decoded = attribute[1]
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
    return JSON.parse(decoded);
}
class Actor {
    cookies = new Map();
    constructor(kind = 'owner') {
        this.kind = kind;
    }
    async request(method, url, data, options = {}) {
        const headers = {
            Accept: 'application/json',
            Cookie: [...this.cookies].map(([a, b]) => `${a}=${b}`).join('; '),
            ...options.headers,
        };
        if (data !== undefined && !(data instanceof FormData))
            headers['Content-Type'] = 'application/json';
        if (options.csrf !== false && this.cookies.has('XSRF-TOKEN'))
            headers['X-XSRF-TOKEN'] = decodeURIComponent(
                this.cookies.get('XSRF-TOKEN'),
            );
        const start = performance.now();
        const requestOptions = {
            method,
            headers,
            redirect: 'manual',
            signal: AbortSignal.timeout(30_000),
        };
        if (data !== undefined)
            requestOptions.body =
                data instanceof FormData ? data : JSON.stringify(data);
        const response = await fetch(base + url, requestOptions);
        const body = await response.text();
        for (const cookie of response.headers.getSetCookie()) {
            const pair = cookie.split(';')[0];
            const index = pair.indexOf('=');
            this.cookies.set(pair.slice(0, index), pair.slice(index + 1));
            secrets.add(pair.slice(index + 1));
        }
        const expected =
            options.status ||
            (method === 'GET' ? [200] : [200, 201, 204, 302, 303]);
        if (!expected.includes(response.status)) {
            writeFileSync(path.join(directory, 'failed-response.txt'), body);
            throw new Error(
                `Unexpected HTTP ${response.status}: ${method} ${url}`,
            );
        }
        return {
            status: response.status,
            body,
            location: response.headers.get('location'),
            client_ms: performance.now() - start,
        };
    }
    async login(password = env.HWO_ACCEPTANCE_PASSWORD) {
        await this.request('GET', '/login');
        await this.request('POST', '/login', {
            email: `acceptance-${this.kind}@example.test`,
            password,
        });
        await this.request('GET', '/dashboard');
    }
    async write(url, data, options) {
        const response = await this.request(
            'POST',
            url,
            { idempotency_key: key(), ...data },
            options,
        );
        return JSON.parse(response.body);
    }
}
const child = spawn(
    'php',
    [
        '-d',
        'pcov.enabled=0',
        '-d',
        'variables_order=GPCS',
        '-d',
        'opcache.enable_cli=1',
        '-S',
        `127.0.0.1:${port}`,
        'scripts/acceptance-server.php',
    ],
    {
        cwd: root,
        env: { ...env, APP_RUNNING_IN_CONSOLE: 'false' },
        stdio: ['ignore', 'pipe', 'pipe'],
    },
);
let serverLog = '';
child.stdout.on('data', (chunk) => {
    serverLog += chunk;
});
child.stderr.on('data', (chunk) => {
    serverLog += chunk;
});
const report = {
    schema: 1,
    phase: 'M5-P2',
    source_sha256: source,
    junit_sha256: existsSync(path.join(root, 'build/junit.xml'))
        ? createHash('sha256')
              .update(readFileSync(path.join(root, 'build/junit.xml')))
              .digest('hex')
        : null,
    started_at: new Date().toISOString(),
    result: 'PENDING',
};
try {
    const prepared = reset();
    for (let i = 0; ; i++) {
        try {
            await fetch(base + '/up', { signal: AbortSignal.timeout(1000) });
            break;
        } catch (error) {
            if (i > 40 || child.exitCode !== null) throw error;
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
    }
    const owner = new Actor();
    await owner.login();
    const foreign = new Actor('foreign');
    await foreign.login();
    const admin = new Actor('admin');
    await admin.login();
    const createAccount = async (name, type) => {
        const response = await owner.request('POST', '/accounts', {
            name,
            type,
            currency_code: 'QAR',
            opening_balance: '',
        });
        const id = response.location?.match(/\/accounts\/(\d+)$/)?.[1];
        assert(id, 'Account creation must return its owned URL');
        return id;
    };
    const bank = await createAccount('Synthetic salary cash', 'bank');
    const emergency = await createAccount('Synthetic emergency cash', 'cash');
    const investment = await createAccount(
        'Synthetic investment cash',
        'investment',
    );
    const transaction = (kind, account, amount, extra = {}) =>
        owner.write('/transactions', {
            kind,
            account_id: account,
            destination_account_id: '',
            amount,
            effective_date: prepared.date,
            description: 'Synthetic manual acceptance',
            receipt_reference: '',
            ...extra,
        });
    const salary = await transaction('income', bank, '1000');
    await transaction('expense', bank, '100');
    const goal = await owner.write('/goals', {
        name: 'Synthetic linked emergency goal',
        account_id: emergency,
        currency: 'QAR',
        mode: 'dedicated',
        target: '500',
        opening: '0',
        policy: 'fixed_monthly',
        contribution: '100',
        first_deposit: '',
        target_date: '',
        standing_order: '1',
        closed: '0',
        reason: 'Synthetic explicit funding plan',
    });
    const transfer = await transaction('transfer', bank, '200', {
        destination_account_id: emergency,
    });
    await transaction('transfer', bank, '300', {
        destination_account_id: investment,
    });
    const goalLink = {
        idempotency_key: key(),
        posting_id: fixture('posting', transfer.id).id,
        reason: 'Synthetic single transfer attribution',
    };
    const linked = await owner.write(`/goals/${goal.id}/links`, goalLink);
    assert.equal(
        (await owner.write(`/goals/${goal.id}/links`, goalLink)).id,
        linked.id,
    );
    const goalEvidence = fixture('goal', goal.id);
    assert.equal(goalEvidence.links, 1);
    assert.equal(goalEvidence.backed_minor, '20000');
    const asset = await owner.write('/assets', {
        prior_id: '',
        name: 'Synthetic acceptance fund',
        kind: 'unitized_fund',
        currency: 'QAR',
        identifier: 'SYNTHETIC-ACCEPTANCE',
        market: 'Synthetic market',
        source: 'Synthetic catalog evidence',
        unsupported_terms: '',
        reason: 'Synthetic owner entry',
    });
    const portfolio = await owner.write('/portfolio', {
        account_id: investment,
        platform_label: 'Synthetic manual platform',
        source: 'Synthetic recorded account',
    });
    await owner.write(`/portfolio/${portfolio.id}/opening`, {
        prior_id: '',
        as_of: prepared.date,
        source: 'Synthetic empty opening book',
        reason: 'Synthetic opening',
        rows: [],
    });
    const preview = await owner.write(`/portfolio/${portfolio.id}/previews`, {
        mode: 'detailed',
        platform_label: 'Synthetic manual platform',
        source: 'Synthetic statement',
        reason: 'Synthetic explicit mode',
        aggregate_value: '',
        aggregate_as_of: '',
    });
    await owner.write(`/portfolio/${portfolio.id}/confirm`, {
        preview_id: preview.id,
        acknowledge: '1',
    });
    const beforePlan = fixture('inspect');
    const plan = await owner.write('/opportunities', {
        ...prepared.plan,
        asset_id: asset.id,
    });
    assert.equal(
        fixture('inspect').postings,
        beforePlan.postings,
        'Planning must not post cash',
    );
    assert.equal(
        fixture('inspect').events,
        beforePlan.events,
        'Planning must not create shares',
    );
    const event = (kind, changes = {}) =>
        owner.write(`/portfolio/${portfolio.id}/events`, {
            kind,
            asset_id: asset.id,
            effective_date: prepared.date,
            quantity: '',
            unit_price: '',
            amount: '100',
            fee: '0',
            tax: '0',
            split_numerator: '',
            split_denominator: '',
            subscription_id: '',
            confirmed: '',
            replaces_id: '',
            action_reference: key(),
            source: 'Synthetic original event evidence',
            reason: 'Synthetic actual fact',
            ...changes,
        });
    const application = await event('ipo_intent');
    await event('ipo_pay', { subscription_id: application.id });
    const paid = fixture('inspect');
    assert.equal(paid.dashboard.facts.widgets.net_worth.total.minor, '90000');
    assert.equal(
        paid.balances.find((row) => String(row.id) === investment).minor,
        '20000',
    );
    assert.equal(
        paid.positions.length,
        0,
        'Paid application is a receivable, not shares',
    );
    await event('ipo_allot', {
        subscription_id: application.id,
        amount: '80',
        quantity: '8',
        unit_price: '10',
        confirmed: '1',
    });
    await event('ipo_refund', {
        subscription_id: application.id,
        amount: '20',
    });
    const instant = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
    await owner.write(`/portfolio/${portfolio.id}/prices`, {
        context: fixture('price-context', portfolio.id).context,
        asset_id: asset.id,
        unit_price: '12',
        source: 'Synthetic dated valuation observation',
        observed_at: instant,
        effective_at: instant,
        supersedes_id: '',
        reason: 'Synthetic mark',
        unit_basis_confirmed: '1',
    });
    const valuation = await owner.write('/valuations', {
        base_currency: 'QAR',
        cutoff: instant,
        start: '',
        account_id: investment,
    });
    const valuationExport = JSON.parse(
        (await owner.request('GET', `${valuation.url}/export`)).body,
    );
    assert.equal(valuationExport.ending.total.minor, '31600');
    const final = fixture('inspect');
    assert.equal(final.unbalanced, 0);
    assert.equal(final.positions.length, 1);
    assert.equal(final.positions[0].quantity, '8');
    assert.equal(final.dashboard.facts.widgets.net_worth.total.minor, '91600');
    const saved = await owner.write('/dashboard/snapshots', {
        settings_id: '',
        base_currency: 'QAR',
    });
    const exportPath = `/dashboard/snapshots/${saved.id}/export`;
    assert.equal(
        JSON.parse((await owner.request('GET', exportPath)).body).facts.widgets
            .net_worth.total.minor,
        '91600',
    );
    const foreignPaths = [
        `/accounts/${bank}`,
        `/transactions/${salary.id}`,
        `/goals/${goal.id}`,
        `/assets/${asset.id}`,
        `/portfolio/${portfolio.id}`,
        `/portfolio/${portfolio.id}/events/${application.id}`,
        `/opportunities/${plan.id}`,
        `/dashboard/snapshots/${saved.id}`,
        exportPath,
        valuation.url,
        `${valuation.url}/export`,
    ];
    for (const actor of [foreign, admin])
        for (const url of foreignPaths) {
            const response = await actor.request('GET', url, undefined, {
                status: [404],
            });
            assert(
                !response.body.includes('Synthetic acceptance fund'),
                'Foreign response must not leak source names',
            );
        }
    const protectedRoutes = [
        '/accounts',
        '/transactions',
        '/fx',
        '/rates',
        '/reports',
        '/reconciliations',
        '/budgets/categories',
        '/budgets/schedules',
        '/goals',
        '/cash/settings',
        '/assets',
        '/portfolio',
        `/portfolio/${portfolio.id}/events`,
        `/portfolio/${portfolio.id}/prices`,
        `/assets/${asset.id}/assessments`,
        '/purification/obligations',
        '/purification/payments',
        '/opportunities',
        '/valuations',
        '/dashboard/snapshots',
        '/dashboard/rules',
    ];
    const boundaryState = fixture('fingerprint').sha256;
    const uploadFilesBefore = storedFiles(
        path.join(env.LARAVEL_STORAGE_PATH, 'app'),
    );
    for (const url of protectedRoutes) {
        await owner.request(
            'POST',
            url,
            {},
            {
                csrf: false,
                headers: {
                    Origin: 'https://foreign.invalid',
                    'Sec-Fetch-Site': 'cross-site',
                },
                status: [419, 403],
            },
        );
        const spoof = await owner.request(
            'POST',
            url,
            { idempotency_key: key(), owner_id: prepared.users.foreign },
            { status: [422] },
        );
        assert(
            JSON.parse(spoof.body).errors.owner_id,
            `Forged owner must be rejected by ${url}`,
        );
        const upload = new FormData();
        upload.set('idempotency_key', key());
        upload.set(
            'attachment',
            new Blob(['<?php echo "SYNTHETIC-NEVER-EXECUTE";'], {
                type: 'application/x-php',
            }),
            '../../synthetic.php',
        );
        const refused = await owner.request('POST', url, upload, {
            status: [422],
        });
        assert(
            JSON.parse(refused.body).errors.attachment,
            `Unsupported file must be rejected by ${url}`,
        );
    }
    assert.equal(
        fixture('fingerprint').sha256,
        boundaryState,
        'Rejected requests must not mutate any owned financial table',
    );
    const unsignedUpload = new FormData();
    unsignedUpload.set(
        'files[]',
        new Blob(['SYNTHETIC-UNSIGNED-UPLOAD']),
        'synthetic.txt',
    );
    await owner.request('POST', prepared.upload_path, unsignedUpload, {
        status: [401],
    });
    assert.deepEqual(
        storedFiles(path.join(env.LARAVEL_STORAGE_PATH, 'app')),
        uploadFilesBefore,
        'Rejected uploads must store no files',
    );
    const protectedPayload = {
        kind: 'expense',
        account_id: bank,
        destination_account_id: '',
        amount: '1',
        effective_date: prepared.date,
        description: 'Synthetic security control',
        receipt_reference: '',
        idempotency_key: key(),
    };
    const count = fixture('inspect').postings;
    await owner.request('POST', '/transactions', protectedPayload, {
        csrf: false,
        headers: {
            Origin: 'https://foreign.invalid',
            'Sec-Fetch-Site': 'cross-site',
        },
        status: [419, 403],
    });
    await owner.request(
        'POST',
        '/transactions',
        { ...protectedPayload, owner_id: prepared.users.foreign },
        { status: [422] },
    );
    await owner.request(
        'POST',
        '/transactions',
        { ...protectedPayload, account_id: '999999999' },
        { status: [404] },
    );
    assert.equal(fixture('inspect').postings, count);
    const validControl = await transaction('expense', bank, '1');
    assert(validControl.id);
    const first = new Actor();
    const second = new Actor();
    await first.login();
    await second.login();
    await first.request('PUT', '/settings/password', {
        current_password: env.HWO_ACCEPTANCE_PASSWORD,
        password: env.HWO_ACCEPTANCE_NEW_PASSWORD,
        password_confirmation: env.HWO_ACCEPTANCE_NEW_PASSWORD,
    });
    await first.request('GET', '/dashboard');
    await second.request('GET', exportPath, undefined, { status: [401] });
    const recovered = new Actor();
    await recovered.login(env.HWO_ACCEPTANCE_NEW_PASSWORD);
    env.HWO_ACCEPTANCE_NEW_PASSWORD = `Synthetic-reset-${randomBytes(16).toString('hex')}`;
    secrets.add(env.HWO_ACCEPTANCE_NEW_PASSWORD);
    fixture('reset-password');
    await recovered.request('GET', '/dashboard', undefined, { status: [401] });
    const logs = readdirSync(path.join(env.LARAVEL_STORAGE_PATH, 'logs'))
        .filter((file) => file.endsWith('.log'))
        .map((file) =>
            readFileSync(
                path.join(env.LARAVEL_STORAGE_PATH, 'logs', file),
                'utf8',
            ),
        )
        .join('\n');
    assert(
        logs.includes('auth.login'),
        'Real authentication log positive control is required',
    );
    for (const secret of [
        ...secrets,
        'acceptance-owner@example.test',
        'Synthetic manual acceptance',
    ])
        assert(
            secret && !logs.includes(secret) && !serverLog.includes(secret),
            'Logs must not contain synthetic credentials, sessions or financial input',
        );
    report.journey = {
        result: 'PASS',
        expected_net_worth_minor: '91600',
        exact_journal_balance: true,
        transfer_link_once: true,
        paid_application_not_shares: true,
        confirmed_holdings: 1,
    };
    report.security = {
        result: 'PASS',
        foreign_owner_and_admin_reads: foreignPaths.length * 2,
        protected_route_count: protectedRoutes.length,
        multipart_files: 'REJECTED_NO_FINANCIAL_WRITES',
        sensitive_logs:
            'NO_SYNTHETIC_SECRETS_OR_FINANCIAL_INPUT_WITH_AUTH_POSITIVE_CONTROL',
        cross_site_no_token: 'DENIED',
        positive_write_control: 'PASS',
        forged_owner: 'DENIED',
        password_change_current_session: 'RETAINED',
        stale_change_and_reset_sessions: 'DENIED',
    };
    reset();
    console.log(
        'HTTP journey and security boundaries passed; seeding workload',
    );
    const workload = fixture('benchmark');
    assert.equal(workload.postings, 10000);
    assert.equal(workload.holdings, 100);
    const benchmarkOwner = new Actor();
    await benchmarkOwner.login();
    const request = async (id, profile = false) => {
        const response = await benchmarkOwner.request(
            'GET',
            '/dashboard?base_currency=QAR',
            undefined,
            {
                headers: {
                    'X-HWO-Measure': id,
                    ...(profile ? { 'X-HWO-Profile': '1' } : {}),
                },
            },
        );
        const props = page(response.body).props;
        assert.equal(
            props.dashboard.widgets.net_worth.total.minor,
            workload.expected_net_worth_minor,
        );
        return response.client_ms;
    };
    for (let i = 0; i < 5; i++) await request(`warm-${i}`, i === 0);
    console.log(
        'Workload verified; measuring 30 sequential dashboard requests',
    );
    const clients = [];
    for (let i = 0; i < 30; i++) clients.push(await request(`sample-${i}`));
    const records = readFileSync(metricsFile, 'utf8')
        .trim()
        .split('\n')
        .map(JSON.parse);
    const samples = records.filter((r) => r.id.startsWith('sample-'));
    assert.equal(samples.length, 30);
    assert(
        samples.every(
            (r) =>
                r.status === 200 &&
                r.console === false &&
                r.queries > 0 &&
                r.variables_order === 'GPCS' &&
                r.owned_storage === true,
        ),
    );
    const sorted = samples.map((r) => r.server_ms).sort((a, b) => a - b);
    const p95 = sorted[Math.ceil(0.95 * 30) - 1];
    report.performance = {
        result: p95 <= 1000 ? 'PASS' : 'FAIL',
        workload,
        warmups: 5,
        runs: 30,
        p95_rule: 'nearest-rank',
        p95_ms: p95,
        server_ms: samples.map((r) => r.server_ms),
        client_ms: clients,
        queries: samples.map((r) => r.queries),
        query_ms: samples.map((r) => r.query_ms),
        profile: records.filter((r) => r.id === 'warm-0'),
        slow_query_plans: fixture('query-plans'),
        cache: 'persistent file cache and sessions; no whole-response cache',
        environment: {
            os: os.type(),
            release: os.release(),
            arch: os.arch(),
            cpu: os.cpus()[0]?.model,
            logical_cpus: os.cpus().length,
            total_memory_bytes: os.totalmem(),
            load_at_report: os.loadavg(),
            opcache_cli: true,
            php: php(['-v']).split('\n')[0],
            postgres: php(['scripts/verify-database.php']).trim(),
            node: process.version,
            coverage_during_measurement: false,
            php_variables_order: samples[0].variables_order,
            owned_native_storage: samples.every(
                (r) => r.owned_storage === true,
            ),
        },
    };
    assert(
        p95 <= 1000,
        `Dashboard server p95 exceeds 1000ms (${p95.toFixed(2)}ms)`,
    );
    assert.equal(sourceIdentity(), source, 'Source changed during acceptance');
    report.result = 'PASS';
} catch (error) {
    report.result = 'FAIL';
    report.error = error.message;
    process.exitCode = 1;
} finally {
    child.kill('SIGTERM');
    await new Promise((resolve) => {
        if (child.exitCode !== null || child.signalCode !== null) resolve();
        else child.once('exit', resolve);
    });
    writeFileSync(path.join(directory, 'server.log'), serverLog);
    try {
        php(['scripts/verify-database.php']);
        php(['artisan', 'migrate:fresh', '--force', '--no-interaction']);
        report.cleanup = 'OWNED_SERVER_STOPPED_AND_DATABASE_RESET';
    } catch (error) {
        report.result = 'FAIL';
        report.cleanup = 'FAILED';
        report.cleanup_error = error.message;
        process.exitCode = 1;
    }
    report.finished_at = new Date().toISOString();
    mkdirSync(path.join(root, 'build'), { recursive: true });
    writeFileSync(
        path.join(root, 'build/acceptance-http.json'),
        JSON.stringify(report, null, 2) + '\n',
    );
    console.log(
        `HTTP acceptance: ${report.result}; journey=${report.journey?.result || 'NOT_COMPLETE'}; performance=${report.performance?.result || 'NOT_RUN'}; cleanup=${report.cleanup}`,
    );
    if (report.error) console.error(report.error);
}
