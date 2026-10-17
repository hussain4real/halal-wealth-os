# M0-P2 verified successor checkpoint

Outcome: **BLOCKED; local baseline verified; not accepted**. Only M0-P1 remains
accepted. No milestone, publication, hosted CI, production or deployment gate
has been accepted or claimed.

## Verified exclusive transfer

The predecessor stopped implementation explicitly and completed preservation
and independent source review. The manager authorized this sole successor after
new official download access succeeded. The recovery archive's external SHA-256,
size, safe paths, all 418 manifest entries and checksum-list entries passed.
All 350 source files and executable modes were verified after restoration into a
new checkout. The complete baseline bundle verified at
`9f8db16620aabec70691538d82066eddc2d01e49`. Existing workspaces were preserved;
no old path or source transfer was assumed. The sole claim changed only after
verification. Exact private identities, paths and logs remain outside this tree.

The prior five cycles and three unsuccessful runtime build attempts remain
historical evidence. The renewed manager checkpoint permits this run; it is not
a blind replay of the earlier nested proxy/trust build.

## Runtime and starter completion

PHP 8.5.11 and extensions run natively from a task-owned prefix derived from the
digest-pinned official image. Signed official Debian build packages and official
PECL Xdebug 3.5.3 were downloaded through the working host proxy, then compiled
with the Docker build network disabled. Composer 2.10.3 matches its upstream
SHA-256. Host Node 24.19.0/npm 11.9.0 remain available to the complete starter.
No host packages/services, CA imports, TLS bypasses, proxy changes, persistent
access credentials or production resources were introduced.

PostgreSQL/psql 18.6 run in an unprivileged disposable container with no network
or published ports, tmpfs data and a private Unix socket. Development and test
databases are separate. The guard recognizes only the exact owned socket and
previous local targets; arbitrary sockets remain rejected. New regression cases
cover both the approved socket and rejection of another socket.

The full official starter at `d282e817c6c2fa1bd475f7c42ea785ccfc67d0ab` was
finalized with all five auth features selected. Chisel's documented finalizer
removed its bootstrap command/scripts and feature markers. Composer dependency
ranges/configuration and the approved npm manifests remain unchanged. The final
Composer lock hash was refreshed without changing resolved package versions.
The handoff npm lock remains byte-identical. See the runtime source manifest,
bootstrap decision and both lockfiles for exact versions.

The first development-server attempt exposed Laravel's filtered child environment
losing the task-local supplemental-library path. The launcher now uses Symfony's
supported PHP_BINARY override to re-enter the same runtime wrapper. No vendor
files or application environment-filtering rules were modified. Full
`composer dev -- --inline --no-restart` then started HTTP, queue, log and Vite
processes with loopback-only listeners. Processes were stopped after inspection.

## Observed verification

| Check                            | Observed result                                                                                                            |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Host official endpoint preflight | Composer/PECL/PHP/fonts 200; GitHub 303; TLS verification passed; later Composer dist installation succeeded               |
| npm lock installation            | npm ci exit 0; lock hash unchanged                                                                                         |
| Composer resolution/finalizer    | Full dependency set installed; all five auth features retained; strict lock validation passes                              |
| Clean source copy                | Fresh Composer and npm lock installs with no copied dependency trees or generated assets; verify --all exit 0              |
| PostgreSQL smoke                 | Verified actual test-database identity; migrate:fresh, rollback, migrate and status pass                                   |
| Frontend                         | Bundled lint/format, vue-tsc and production build pass                                                                     |
| Backend                          | Pint and Larastan/PHPStan pass                                                                                             |
| Real application tests           | 52 passed, 155 assertions; no required skips; both Unit and Feature discover tests                                         |
| Coverage                         | Real Xdebug reports; 145/155 application statements covered                                                                |
| Deliberate failure               | Passing baseline; intended 53rd test fails through bin/verify exit 1; only probe removed; restored 52-test baseline passes |
| Negative prerequisite checks     | Missing coverage, missing explicit DB marker, unavailable DB and missing psql each refuse with exit 2                      |
| Lifecycle/support tests          | 11 Node tests pass; M0-P1 planning harness passes                                                                          |
| Advisories                       | Composer zero advisories; npm zero vulnerabilities                                                                         |
| Development/browser              | All four dev processes start; Chromium hydrates landing/login/register; full browser smoke remains blocked below           |
| Hosted CI                        | NOT_RUN; workflow invokes the same failure probe but no hosted execution is claimed                                        |

A fresh verification-only copy used the same approved runtime and a guarded
socket path to the same disposable test database. Checks ran sequentially against
that database. Its source/dependency identities and exact command exits are kept
privately. Financial coverage/performance and later domain suites are not implied.

## Remaining browser blocker

The unchanged starter Welcome page requests `https://rsms.me/inter/inter.css`.
Chromium reports `net::ERR_CERT_AUTHORITY_INVALID`, including when launched with
the existing configured proxy and loopback bypass. The supplied proxy CA's public
fingerprint already appears trusted for SSL in the existing NSS store. A read-only
OpenSSL handshake through the same proxy verifies the endpoint and that CA with
return code zero. This is a browser trust/runtime discrepancy, not a demonstrated
missing host CA. No trust change is verified or approved, and no font substitution,
request interception or disabled TLS was used to conceal it.

The browser script also required locator/navigation corrections; its final form
submission assertion did not establish a successful request. Core page hydration
is observed, but strict browser success and invalid-login UI validation remain
unproved. Stop browser retries for environment diagnosis and a separately reviewed
specific trust change if one is necessary. Do not blindly import another CA: the
existing fingerprint already matches. Chromium's documented Linux NSS behavior is
at https://chromium.googlesource.com/chromium/src/+/main/docs/linux/cert_management.md;
Playwright's explicit proxy configuration is documented at
https://playwright.dev/python/docs/network#http-proxy.

## Acceptance and invariant mapping

| Requirement                      | Status / evidence                                                                                  |
| -------------------------------- | -------------------------------------------------------------------------------------------------- |
| M0-P2-AC1                        | Local clean lock install, frontend build and PostgreSQL baseline PASS                              |
| M0-P2-AC2                        | Real local failure/restoration PASS; hosted CI portion NOT_RUN                                     |
| M0-P2-AC3                        | Real tool, coverage, marker and database refusals PASS                                             |
| M0-P2-AC4                        | Guarded disposable identity and real migration smoke PASS                                          |
| M0-P2-AC5                        | Backend application smoke and frontend lint/types PASS; strict browser completion BLOCKED          |
| INV-12                           | Actual results and missing evidence retained; no phase/milestone acceptance or CI success invented |
| INV-10                           | Full auth retained; financial resource ownership/admin remain later-phase work                     |
| INV-01 through INV-09 and INV-11 | Not applicable to unimplemented financial domains; future gates preserved                          |

## Bounded cycles and review

1. Verify exclusive archive/source/baseline transfer, claim ownership, acquire
   official artifacts and establish isolated runtimes.
2. Resolve full Composer dependencies, finalize every auth feature and refresh
   only the lock content hash. Preserve both manifest ranges and the npm lock.
3. Correct formatting after state updates; run the complete baseline, negative
   prerequisites and real failure/restoration probe.
4. Prove clean installs, diagnose/fix the PHP child launcher, inspect full dev
   processes/browser and preserve the unresolved strict browser result.
5. Reconcile evidence/state/documentation and request independent final review.

One build invocation stopped at read-only Docker client metadata; a task-owned
client metadata directory fixed that before the offline extension build succeeded.
No nested network/trust build was retried. Browser failures and their different
causes are retained; no further browser execution is authorized automatically.
The sixth cycle remains available only for scoped review corrections within this
checkpoint, never to bypass trust or publication restrictions.

Independent source-read-only final review completed with no additional implementation
defect requiring a source correction within its scope. The reviewer checked all
293 recorded starter paths, official finalizer cleanup, retained auth features,
manifest ranges/locks, runtime wrapper, guards, reports, lifecycle state and
command evidence. It independently reran 11 Node tests, planning and whitespace
checks. It did not rerun application/database/browser checks or hosted CI.
The initial clean-copy inventory preceded the launcher correction; the corrected
launcher was copied before clean verify --all and its tested hash matches the
primary wrapper. Exact source inventories distinguish these snapshots.

The final source outcome is suitable for preservation with the browser and CI
blockers still open. Five renewed cycles were used; the sixth is unused. The
owner has stopped edits with no automatic resumption. Worker status remains
BLOCKED. Hosted CI
requires a separately authorized publication path and observed run. Any later
phase, acceptance, remote publication or deployment requires separate authority.
