# Isolated development runtime

The successor uses native task-local PHP/Composer alongside host Node/npm, with
PostgreSQL in an isolated disposable container. This resolves the earlier
PHP-only container's inability to run the complete `composer dev` command.
It requires Debian 13 x86_64 ABI compatibility; other hosts should provide a
supported native runtime satisfying the same platform checks.

## Source and trust

[Source identities](runtime-sources.json) record official digest-pinned PHP
8.5.11/PostgreSQL 18.6 images, Composer 2.10.3 with its verified upstream SHA-256,
Xdebug 3.5.3 from PECL, and all Debian build-package hashes. Host APT downloaded
packages from signed official Debian trixie/trixie-updates/security indexes,
using the existing Debian archive keyring, into a private task directory.
No host system packages or services were installed.

Composer, PECL, Debian and npm downloads used the host's configured proxy with
TLS verification. The extension image builds with `--network=none`; it needs no
CA material or nested proxy changes. Host/browser trust and global network
settings were not modified. A missing or incompatible trust chain is a blocker
requiring an explicit proposed change and approval, never disabled verification.

## Recreate the task-local PHP prefix

1. Download the official Composer PHAR/checksum and PECL archive named in
   runtime-sources.json. Verify Composer against the upstream checksum and all
   retained artifact hashes. Use the configured proxy and normal verified TLS.
2. In an owned directory, configure APT's state/list/archive paths and source
   list for the three official Debian suites above. Keep the existing signed-by
   Debian keyring. Run update and download-only installation of libpq-dev,
   libicu-dev and libzip-dev against the base image's exported dpkg status.
   This resolves the complete package set recorded in runtime-sources.json.
   Check its hashes; do not install those packages onto the host.
3. Put the packages under build-inputs/archives and Xdebug at
   build-inputs/xdebug-3.5.3.tgz. Use the offline recipe:

```bash
mkdir -p .runtime/docker
DOCKER_CONFIG="$PWD/.runtime/docker" docker build --network=none \
  -f ops/development/php.Dockerfile -t hwo-successor-php:8.5.11 \
  "$PWD/.runtime/build-inputs"
```

4. Create a stopped export container from that image with no network. Copy its
   /usr/local tree to .runtime/php-prefix. Copy its libargon2.so.1 to .runtime/lib;
   extract the verified libicu76/libzip5 packages using dpkg-deb into an owned
   scratch directory, and copy only their shared libraries to .runtime/lib.
   Do not copy or change host libc, loader, certificates or system libraries.
5. Copy the prefix's etc/php/conf.d files to .runtime/php-ini. Add
   00-runtime.ini setting extension_dir to the absolute exported extension
   directory, date.timezone=UTC and memory_limit=512M. Add executable local
   php/composer/psql launchers under .runtime/bin which delegate to
   bin/dev-runtime with the matching first argument. Retain the verified
   Composer PHAR at .runtime/composer.phar. Runtime artifacts remain ignored.
6. Put .runtime/bin on PATH, run `XDEBUG_MODE=coverage php scripts/verify-platform.php`,
   verify versions and stop for any ABI/extension error. Do not substitute a
   partial runtime for a passing platform check.

bin/dev-runtime scopes the extension scan path and supplemental library path
to PHP. It exports Symfony's supported PHP_BINARY executable override to the
local launcher. This lets Laravel's HTTP server rebuild its environment for
configuration reloads while still launching the configured PHP runtime. No
vendor modification or expansion of Laravel's environment passthrough is used.
Native Node and npm remain available to the full starter development command.

## Disposable PostgreSQL

Container `hwo-successor-postgres` uses the pinned official 18.6 image, the
current unprivileged host UID/GID, no network, no ports and tmpfs database data.
Mount the owned mode-0700 .runtime/postgresql directory at /var/run/postgresql.
Initialize role hwo, database halal_wealth_os_dev and an init SQL file creating
halal_wealth_os_test owned by hwo. Use local trust only in this isolated,
passwordless disposable container; no persistent access credentials are created.
Start postgres with `listen_addresses=''` and `unix_socket_permissions=0700`.
Inspect names/resources before creating or reusing them.

Set .env DB_HOST and HWO_TEST_HOST to the absolute owned socket directory.
The test guard recognizes that exact project-local path, the original isolated
/var/run/postgresql socket and loopback addresses. An arbitrary socket path,
remote host, URL override, wrong database/environment or missing explicit
marker is refused before destructive migration. Actual database identity is
queried before migration smoke.

`bin/dev-runtime psql` executes only in the named database container.
`bin/dev-runtime status` reports identity and versions. `bin/dev-runtime stop`
stops only that container and loses its tmpfs data. HTTP/Vite browser-check
processes are stopped after inspection. Never clean up unrelated resources.

## Observed limits

The extension build, real platform check, lock installs, migration smoke,
production build, static checks, 52 tests, coverage and deliberate failure /
restoration have executed successfully. Browser details and the clean-copy run
are recorded in the [successor phase run](../../docs/halal-wealth-os/runs/M0-P2-successor.md).
The optional fontaine optimization warning does not remove a starter dependency
or feature; no unapproved optional package was added. Hosted CI remains NOT_RUN.

## Current support and fast local tests

The application accepts only PHP8.5.x, with PHP8.5.11 verified here; the runtime
checker refuses PHP8.4 and future minor lines. Pest5.3.2/Laravel plugin5.0.1
and PHPUnit13.4.2 are locked. Functional Pest cases retain original assertions
and RefreshDatabase isolation. TIA needs enabled Xdebug/PCOV in every process.
Its cache is explicitly .runtime/pest/tia, already ignored; no shared remote
baseline, account, credential or paid service is enabled. defaultBranch('dev')
matches the integration branch and must resolve locally. Node and installed
Vite Plus packages are needed for the Vue dependency graph.

Use composer test:impact:record, then composer test:impact for local acceleration.
The initial missing baseline is blocked. Full verification and hosted CI retain
Pest --ci --no-tia with actual coverage and all frontend/database gates.

The current Pest resolver cannot transform shared Vue components. The local
wrapper conservatively rebuilds the whole TIA baseline on shared frontend or
resolver configuration changes; PHP and individual Inertia pages keep native
selection. Both graph and branch-bound frontend snapshot are required.

## Disposable-resource ownership limit

The database name and explicit local marker identify the selected target;
they do not independently attest that its contents are disposable. Verification
is restricted to a separately inspected, task-owned isolated PostgreSQL
container or the disposable CI service. Never mark a same-named database on a
shared local server disposable. This review checkpoint adds no server-side
sentinel or database authentication change.
