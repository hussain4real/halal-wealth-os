# Halal Wealth OS

A private personal wealth application bootstrapped with the complete official
Laravel Inertia/Vue starter. Public source visibility does not authorize public
SaaS or access to personal financial data.

**M0-P2 is ready for review and remains unaccepted.** The local application
baseline and original hosted CI pass. The sanitized scaffold is published in
[PR #1](https://github.com/hussain4real/halal-wealth-os/pull/1) targeting dev. See
[the merge checkpoint](docs/halal-wealth-os/runs/M0-P2-merge.md) and
[publication run](docs/halal-wealth-os/runs/M0-P2-publication.md) for current
evidence and remaining gates. Only M0-P1 is accepted.

The starter is pinned to `d282e817c6c2fa1bd475f7c42ea785ccfc67d0ab`. All five
optional authentication features were retained by its official finalizer:
registration, email verification, two-factor authentication, passkeys and
password confirmation. Bundled UI components and development tooling remain.
The approved Vite Plus 0.3.3 patch includes the matching Vite core alias and
bundled Vitest override. Both dependency locks record actual resolution.
No financial functionality or domain owner-isolation/admin work is implemented.

## Runtime

Only PHP **8.5.x** is supported (`~8.5.0`); PHP 8.4, 8.6 and other minors
are rejected. The verified runtime is PHP 8.5.11 with the extensions checked by
`scripts/verify-platform.php`,
Xdebug 3.5.3 with coverage enabled for verification, Composer 2.10.3,
Node 24.19.0/npm 11.9.0 and PostgreSQL server/client 18.6. The cloud runtime
uses task-local native PHP alongside the existing Node tools, plus a disposable
PostgreSQL container with no network or published ports. Source identities,
reproduction and lifecycle notes are in [development runtime notes](ops/development/README.md).

In that prepared cloud checkout:

```bash
export PATH="$PWD/.runtime/bin:$PATH"
export npm_config_cache="$PWD/.runtime/npm-cache"
export NODE_USE_ENV_PROXY=1
bin/dev-runtime status
```

The Node flag uses the environment's existing configured proxy. Keep TLS
verification enabled and retain the environment's provided trust configuration.
Do not introduce alternate download hosts to bypass a denial.

## Install from locks

These steps assume a verified runtime and separately created disposable
development/test PostgreSQL databases. A fresh verification copy successfully
installed both locks without copying dependencies or generated assets.

```bash
test -f composer.lock && test -f package-lock.json
composer install --prefer-dist --no-interaction --no-scripts
php artisan package:discover --ansi
npm ci --ignore-scripts --no-fund
cp .env.example .env
cp .env.testing.example .env.testing
```

Set development configuration to `halal_wealth_os_dev`, and testing configuration
to `halal_wealth_os_test`. The cloud native PHP process uses the absolute path of
`$PWD/.runtime/postgresql`; a normal native PostgreSQL runtime may use loopback.
Never use a production or shared database. Generate only a local application
encryption key; it stays in ignored local configuration:

```bash
php artisan key:generate
php artisan migrate
php artisan wayfinder:generate --with-form --no-interaction
npm run build
composer dev -- --inline --no-restart
```

The full development command retains the HTTP server, queue listener, log viewer
and Vite. Bind servers to loopback. Development uses a sync queue, file
cache/session and log mail. Tests use sync/array stores and a separate disposable
database. No Redis, external mail or external account credentials are needed.

Do not rerun project generation or blindly invoke upstream `composer setup`:
it includes key generation and migration. Official feature finalization has
already run in this source; its bootstrap scripts removed themselves as designed.

## Verification

```bash
export HWO_DISPOSABLE_DATABASE=halal_wealth_os_test
export HWO_TEST_HOST="$PWD/.runtime/postgresql"
export HWO_TEST_USER=hwo
./bin/verify --phase M0-P1
./bin/verify --phase M0-P2
./bin/verify --all
./bin/probe-failure
```

The project-owned harness runs planning/lifecycle tests, tool/lock/platform and
advisory gates, guarded PostgreSQL migrate/rollback/remigrate smoke, generated
Wayfinder resources, frontend lint/types/build, Pint, Larastan and Pest with
real coverage reports. Missing tools, coverage or databases, unknown phases,
empty required suites, skipped required tests and missing reports fail.

The deliberate failure probe passed its 52-case baseline, observed the intended
53rd failing Pest case through `bin/verify` exit 1, removed only that temporary
test and restored a successful full `bin/verify --all` baseline. The same command passed in
[the original CI run](https://github.com/hussain4real/halal-wealth-os/actions/runs/39040426083).
The follow-up CI setup copies the public testing template to prevent missing-file
warnings; the latest result is visible in PR checks. Reports are ignored local `build/junit.xml` and `build/coverage.xml`.

The current application suite has 55 passing tests and 171 assertions; coverage
includes 149 of 159 application statements. The original 52 cases remain, with
three profile-email regressions added after independent pre-merge review. These are bootstrap results, not
future financial coverage, ownership, integration, performance or release gates.
Both dependency advisory checks report zero findings.

[Planning index](docs/halal-wealth-os/INDEX.md) ·
[Acceptance boundary](docs/halal-wealth-os/ACCEPTANCE.md) ·
[Phase requirements](docs/halal-wealth-os/phases/M0-P2.md) ·
[Original starter README](docs/halal-wealth-os/STARTER-README.md)

## Branch workflow and Pest TIA

The owner approved main, stage and dev. Every pull request targets **dev**.
Preserve existing branches; do not merge, promote or deploy without separate
authorization. The M0-P2 feature branch may be published for hosted CI and review.
Current publication evidence is tracked in
[the publication run](docs/halal-wealth-os/runs/M0-P2-publication.md).

Pest 5.3.2, its Laravel plugin 5.0.1 and PHPUnit 13.4.2 replace the earlier
PHPUnit-only runner. The functional conversion preserves the 52 scenarios,
155 assertions, seven dataset rows, five setup hooks and database isolation.
No starter feature or other dependency requirement was removed.

TIA is a local developer loop with a per-checkout ignored cache. It needs an
enabled coverage driver, Node, installed frontend packages, Git history and the
local dev comparison branch. Shared frontend/configuration changes conservatively
force a full fresh TIA recording because the bundled resolver cannot transform
Vue single-file components. PHP and Inertia page edits retain native selection.
It does not fetch remote baselines or need new
credentials/services. With the disposable test environment variables above:

```bash
composer test:impact:record  # full local TIA graph/baseline recording
composer test:impact         # affected execution plus cached-result replay
./bin/verify --all           # mandatory full uncached Pest + coverage gate
```

The impacted command refuses a missing/invalid baseline and missing tools,
coverage, suites or test database. Cache replay is never full-suite evidence.
CI and full verification explicitly use Pest --ci --no-tia and retain frontend
lint/types/build, database checks, coverage and deliberate failure/restoration.
TIA files, installed dependencies and real environment files must never be
committed. Official engine documentation: [Pest TIA](https://pestphp.com/docs/tia).
