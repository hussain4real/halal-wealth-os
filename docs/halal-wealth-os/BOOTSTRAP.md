# Current PHP/test-tooling decisions

The owner subsequently required PHP 8.5.x only and Pest TIA. Application PHP
is ~8.5.0; Pest ^5.3.2 with Laravel plugin ^5.0.1 resolves PHPUnit 13.4.2.
All 52 test cases/155 assertions are preserved through functional syntax for TIA.
Other starter features and supported ranges remain. The local TIA loop never
replaces full uncached CI/coverage. Publication and PR-to-dev are authorized;
merges, deployment, security and trust changes remain excluded. See
[runs/M0-P2-publication.md](runs/M0-P2-publication.md).

# Full starter bootstrap decision

The successor checkpoint completed Composer resolution and official feature
finalization with all five authentication choices retained. The local PostgreSQL,
frontend, application, coverage and failure/restoration gates now pass. Earlier
blocker descriptions below are historical; current evidence and limits are in
[runs/M0-P2-successor.md](runs/M0-P2-successor.md). No phase acceptance is implied.

The human owner approved isolated runtime setup and the full official Vue/Inertia
starter, including its bundled authentication and development benefits. This
supersedes the earlier bare Laravel skeleton and custom minimal package proposal.

Source: laravel/vue-starter-kit commit
`d282e817c6c2fa1bd475f7c42ea785ccfc67d0ab`. Source file hashes and the official
archive checksum are retained in starter-source.json. The original baseline is
Git commit `9f8db16620aabec70691538d82066eddc2d01e49`; no history was rewritten.

The following records the original starter selection, before the later PHP 8.5-only
and Pest 5 TIA decisions. Its imported ranges were retained at that checkpoint.
The historical PHPUnit 12 choice below is superseded by the publication run. Keep
Collision, Fortify, passkeys, two-factor authentication, Chisel, Wayfinder, Pail,
Pao, Sail, Tinker, Larastan, Pint, Vite Plus and the supplied Vue components. The
starter's exact Vite Plus 0.3.0 requires Node ^20.19, ^22.18 or >=24.11. Existing
Node 24.19.0/npm 11.9.0 meet the declared engine ranges. Locks and observed checks,
not declared compatibility alone, establish reproducibility.

Runtime candidates remain PHP 8.5.11, Composer 2.10.3, PostgreSQL 18.6 and Xdebug
3.5.3. Official PHP/PostgreSQL image digests are recorded in the development
runtime and CI definitions. A PHP image without pdo_pgsql and a coverage driver
does not meet the verification gate. No SQLite fallback is permitted.

Development uses an isolated PostgreSQL development database, sync queue, file
cache/session and log mail. Tests use a separate explicitly marked disposable
PostgreSQL database, sync queue, array cache/session and array mail. No Redis,
external mail, cloud account or production access is configured.

Authentication is the full bundled baseline, not evidence of financial resource
ownership isolation. Owner-scoped domain access, two-owner isolation scenarios,
private registration policy and restricted Filament administration remain later
phase work. No financial services or financial fixtures are implemented here.

The starter's original README is retained as STARTER-README.md. Planned project
domain folders supplement its standard app/resources/tests structure. Request
validation/error handling retains Laravel and Inertia conventions.

Initial dependency resolution must suppress create-project/update scripts until
the PostgreSQL environment and full feature answers are configured. Complete the
official feature installer with all five auth features selected; do not prune
features manually. Review its resulting diff and refresh the Composer lock hash
if the installer modifies manifest metadata. Subsequent clean installs must use
the committed locks and avoid rerunning project generation.

Full logs and exact worker/worktree identities remain outside this public
checkout. Publication and hosted CI are not authorized or claimed by this run.

## Material differences requiring a decision

npm audit reports three critical findings through the retained exact Vite Plus 0.3.0 -> oxfmt 0.64.0 -> tinypool 2.1.0 chain. oxfmt pins tinypool exactly, so ordinary lock refresh within the approved ranges does not select its fixes. npm recommends Vite Plus 0.3.3 outside the starter pin. That version changes bundled formatter/linter/core packages and needs explicit approval plus compatibility and audit evidence; no forced fix or override was applied. The upstream advisories require a separate prototype-pollution primitive; this is tooling dependency evidence, not proof of an exploitable application endpoint.

References: [worker-options advisory](https://github.com/tinylibs/tinypool/security/advisories/GHSA-5gmw-xhrv-c9v3) and [run-options advisory](https://github.com/tinylibs/tinypool/security/advisories/GHSA-85c8-ppgw-ccpr).

Required PostgreSQL alignment changes the default connection and test configuration and removes the unused create-project SQLite file hook. Authentication and all dependency ranges remain intact. Verification scripts add coverage and advisory gates without replacing the standard starter commands.

## Human-approved Vite Plus patch

After the initial blocked run, the human explicitly approved 0.3.0 -> 0.3.3. The patch and its required same-version Vite core alias / bundled Vitest override are now applied. All starter features remain. The initial findings and pins above are historical; current npm audit reports zero vulnerabilities. Full application verification remains blocked. See runs/M0-P2-vite-patch.md for exact advisories, manifest alignment, commands, current results and environment requirements.
