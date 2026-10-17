# M0-P2 cloud run

Outcome: **BLOCKED; not accepted**. Only M0-P1 remains human-accepted. No milestone
gate, remote publication, hosted CI, production access or deployment is claimed.

The human approved isolated runtime setup, full official Vue/Inertia starter
scaffolding, tests and verification. The manager supplied the canonical phase
requirements and delegated sole implementation ownership and claim recording to
`cloud-m0-p2-owner`. The dedicated cloud task is the only implementation writer;
the previous local planning task remains idle/read-only. Exact task/worktree
identity and command logs remain outside the public checkout.

Baseline: `9f8db16620aabec70691538d82066eddc2d01e49`, matching remote main at preflight.
The checkout was clean on branch work, with 20 planning files and one worktree.
Git history is preserved. Source: full official laravel/vue-starter-kit commit
`d282e817c6c2fa1bd475f7c42ea785ccfc67d0ab`, archive SHA-256
`114b3e107229356c8442f1afadcf702122c85a57fb928a578e5dd01867676c4d`.
The original source hashes are in ../starter-source.json; original bytes of the
accepted planning baseline remain in Git history.

## Completed work

- Imported the full starter with bundled authentication, passkeys, two-factor
  authentication, UI components and development tooling. Supported dependency
  ranges remain unchanged. The official feature finalizer has not run yet.
- Installed the approved npm dependency set and retained the resolved lock.
  Direct locked examples: Inertia Vue/Vite 3.9.1, Vue 3.5.44, TypeScript 5.9.3,
  Vite 8.3.4, Vite Plus 0.3.0, vue-tsc 2.2.12, Tailwind 4.3.3 and Laravel Vite
  plugin 3.2.0. All optional starter dependency declarations are retained.
- Downloaded official digest-pinned PHP 8.5.11 and PostgreSQL 18.6 images. Created
  isolated development/test databases with no network, published ports or
  persistent password. Database data is disposable tmpfs storage.
- Aligned PostgreSQL examples, test configuration and the default connection.
  Retained Laravel/Inertia request/error conventions, UserFactory and standard
  structure. Added empty domain boundaries, UTC clock support tests, app/database
  smoke tests and disposable-database safety checks. No financial features exist.
- Added executable phase/full verification, deliberate failure probing, guarded
  migration smoke, coverage reports, startup documentation and a PostgreSQL CI
  definition. These are authored code, not passing application/CI evidence.
- Applied the bundled formatter to planning documentation. Historical acceptance,
  source identity and requirement values are preserved; authorization/run status
  changes are explicitly recorded rather than self-accepting the phase.

## Bounded checkpoints

| Cycle | Work/check                                                   | Observed outcome                                                                                                                  | Next action                                                               |
| ----- | ------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| 1     | Claim, full source, runtime images and npm install           | Source/npm installed; npm cache moved to an allowed writable task directory after one cache error                                 | Configure isolated PostgreSQL and verification                            |
| 2     | PostgreSQL, extension build, frontend checks and audit       | PostgreSQL live; build stopped after three attempts at proxy DNS/TLS trust failures; frontend types/build blocked; audit non-zero | Stop runtime retries and record required environment/dependency decisions |
| 3     | Harness safety, syntax, documentation and source diff review | Independent available checks recorded below; application requirements remain blocked                                              | Return to manager; no automatic background resumption                     |

The final evidence budget records five scoped cycles: initial source/runtime setup;
PostgreSQL/harness implementation; initial frontend/audit/runtime verification;
harness negative-check review and lint correction; and final documentation/source
review. Runtime installation stopped at its separate three-failure limit. No
sixth implementation cycle or background runtime retry is claimed.

No denied endpoint was retried or rerouted through a mirror/alternate host. The
three extension-build attempts used the same configured proxy: first its DNS name
was unavailable inside Docker, then TLS verification failed, then supplying the
existing environment CA bundle still failed TLS verification. Verification was
never disabled. The final Dockerfile makes the public CA secret readable to APT
workers; that adjustment has not been retried after the stop limit.

## Observed verification

| Check                    | Command                                                                                   | Exit/result                                                                 |
| ------------------------ | ----------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| Initial npm resolution   | npm install --ignore-scripts --no-audit --no-fund with an allowed task cache              | 0; 237 packages installed, real lock generated                              |
| Bundled formatter/linter | npm run check:fix                                                                         | 0; checked formatting and 64 linted files at that checkpoint                |
| Frontend types           | npm run types:check                                                                       | 2; generated Wayfinder route/action modules unavailable                     |
| Production build         | npm run build                                                                             | 1; Wayfinder invokes unavailable PHP                                        |
| Dependency advisory gate | npm audit --audit-level=high                                                              | 1; three critical reported findings through Vite Plus -> oxfmt -> tinypool  |
| PostgreSQL identity      | isolated container psql SELECT current_database(), current_user, version()                | 0; halal_wealth_os_test, hwo, PostgreSQL 18.6                               |
| PHP source syntax        | official PHP 8.5.11 image, php -l over app/bootstrap/config/database/routes/scripts/tests | 0 at the syntax checkpoint; no PHPUnit tests implied                        |
| Harness support tests    | node --test scripts/verification.test.mjs                                                 | 0; three infrastructure tests, not application failure-propagation evidence |
| Planning harness         | ./bin/verify --phase M0-P1                                                                | 0 at the checkpoint                                                         |
| Final checks             | See final command evidence supplement below                                               | Pending final capture                                                       |

## Acceptance and invariant mapping

| Requirement                      | Evidence                                                                                                                                   | Status                                                                        |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------- |
| M0-P2-AC1                        | Full source/npm lock exist; Composer lock, platform completion, application tests and production build absent                              | BLOCKED                                                                       |
| M0-P2-AC2                        | Real deliberate PHPUnit failure probe and CI step authored; no passing application baseline exists to run the probe                        | BLOCKED; not substituted with a missing-tool failure                          |
| M0-P2-AC3                        | Harness requires tools, both locks, installed dependencies, enabled coverage and actual PostgreSQL; negative infrastructure checks execute | PARTIAL; actual missing tools/extensions/coverage and marker refusal verified |
| M0-P2-AC4                        | Explicit disposable database marker, local-target/identity guard before destructive migrations and guard before RefreshDatabase            | Authored; application migration smoke NOT_RUN                                 |
| M0-P2-AC5                        | Frontend lint executes; type/build failures recorded without stubs; PHP app smoke authored                                                 | BLOCKED; no future suite claimed passed                                       |
| INV-10                           | Bundled auth retained, financial resource ownership/admin work explicitly deferred                                                         | NOT_APPLICABLE to unimplemented domains; no owner-isolation pass claimed      |
| INV-12                           | Exact commands/exits and missing evidence disclosed; no CI/coverage/migration success invented                                             | Applied throughout this run                                                   |
| INV-01 through INV-09 and INV-11 | No financial calculations/postings, reservations, holdings, Shariah, FX, valuation or domain retries implemented                           | NOT_APPLICABLE; future gates retained                                         |

## Remaining blockers and smallest next steps

1. Environment support must repair Docker build proxy trust while keeping normal
   TLS validation and the configured proxy. Official Debian sources must remain
   reachable. No further runtime build retry is authorized by this exhausted
   three-attempt failure budget without a new manager/human checkpoint.
2. Permit the required official source paths: getcomposer.org/download/2.10.3,
   api.github.com/repos/_/_/zipball/* and their normal codeload redirects,
   pecl.php.net/get/xdebug-3.5.3.tgz (or an explicitly selected supported official
   coverage source), and fonts.bunny.net/css2 plus its font resources. Prior
   requests received proxy tunnel 403; there is no alternate-host workaround.
3. Decide on the exact Vite Plus dependency adjustment. The retained 0.3.0 pins
   oxfmt 0.64.0, which pins vulnerable tinypool 2.1.0. npm suggests Vite Plus
   0.3.3 outside the approved starter pin; its engine range still accepts Node
   24.19.0, but changed core/linter/formatter packages require review and actual
   compatibility/audit checks. No forced update, override or feature removal ran.
4. Complete Composer/coverage installation, resolve a real Composer lock from
   approved ranges, run the official installer retaining all five auth features,
   review its changes and prove clean installs from both locks. Then run the real
   PostgreSQL, application, build, coverage and deliberate failure/restoration
   gates. Hosted CI remains a separate observed run after publication authority.

No production secret or persistent access credential was generated. No original
Mac/private archive was assumed transferred. Canonical M0-P2 wording was supplied
by the manager; later private fixtures and specifications remain future inputs.

## Final evidence limits

The local configuration guard and PHPUnit/coverage report path are code-reviewed and syntax-checked; ORM migrations, PHPUnit discovery/execution, the real deliberate failing PHPUnit test, coverage, browser smoke and hosted CI remain NOT_RUN. The missing-tool/driver refusal is observed evidence for prerequisite handling, not a substitute for those checks. Infrastructure tests exercise real child exit propagation but do not satisfy M0-P2-AC2. The final CI client uses the pinned PostgreSQL service image rather than assuming a runner client version.

The separate source review found only these intended upstream-file differences: .env.example, .github/workflows/tests.yml, .gitignore, composer.json scripts, config/database.php, phpunit.xml and tests/TestCase.php. All other imported files and all Composer/npm/optional dependency ranges are retained. All 20 baseline files still exist; roadmap JSON values are unchanged. Full command logs, checkout hashes and exact identity are held privately.

## Subsequent separately authorized tooling patch

The human approved Vite Plus 0.3.3 after this initial blocked run. Its matching core alias and bundled Vitest override were required by verified CLI behavior and the bundled official upgrade guide. The patched npm lock now passes npm audit with zero findings and frontend lint. The original audit observations above remain historical evidence; the dependency decision is resolved. See M0-P2-vite-patch.md for current details. The blocked phase/owner claim and original runtime retry limit remain unchanged; no Docker build or denied download was retried.
