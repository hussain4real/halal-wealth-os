# M0-P2 publication and CI checkpoint

Status: authorized publication preparation; phase remains unaccepted. This
checkpoint supersedes earlier publication restrictions while preserving the
previous five-cycle source/evidence record. No merges, deployments, branch
protection changes, trust-store changes, persistent credentials, external
account connections or financial features are authorized.

The owner requested main, stage and dev, with every PR targeting dev. Remote
inspection found only main at 9f8db16620aabec70691538d82066eddc2d01e49, no open PRs
and no active remote claim. The preserved 350-file source inventory matched.
Missing stage/dev were created from that exact main baseline; main was unchanged.
The same sole scaffold owner uses feat/m0-p2-starter-harness.

The owner additionally required PHP 8.5 only and Pest TIA. Application PHP is
~8.5.0, verified to accept 8.5 and reject 8.3/8.4/8.6/9.0 through Composer's
constraint implementation. Runtime and CI remain PHP 8.5.11. Pest 5.3.2 and the
Laravel plugin 5.0.1 resolve PHPUnit 13.4.2 and work with Laravel 13.37.0. Source
inspection confirmed MIT licensing and local TIA with no new required service,
account, license agreement or credential. Shared baseline fetching is not enabled.

TIA rejects class-based tests. The 46 functional definitions preserve their
original statement-body hashes, five setup hooks and seven dataset cases,
including all database isolation guards. The first full Pest run passed 52 tests
and 155 assertions. Application source hashes are unchanged. Updated coverage
tooling counts one additional covered statement in SecurityController:
146/156 rather than 145/155, with no coverage exclusions or scope change.

The browser diagnosis recorded the exact failing resource:
https://rsms.me/inter/inter.css in Welcome.vue. Computed typography and loaded
font faces were Instrument Sans from the existing bundled font pipeline; Inter
was not used. Only the unused Inter stylesheet/preconnect links were removed.
No TLS bypass, interception, font substitution or trust change is involved.
The browser form limitation was a test-navigation race: waiting for a settled
login page before filling now produces the expected invalid-credentials UI.
Post-change strict Chromium verification passed home/login/registration hydration,
invalid-credentials validation and loaded Instrument Sans, with zero page errors
and zero failed requests. The full development process stayed running during
the check and was stopped afterward.

Local TIA uses the ignored per-checkout .runtime/pest/tia cache and explicit
defaultBranch('dev'), with a verified local dev ref. It requires real coverage,
nonempty Feature/Unit discovery, a valid baseline and the disposable database.
A missing baseline is observed to refuse with exit 2. Full verification and CI
explicitly use --ci --no-tia, keeping all formatting/static/frontend/database,
coverage and deliberate failure/restoration gates. Replayed tests are not
reported as freshly executed full-suite evidence.

Full uncached verification has passed with Pest: 52 tests, 155 assertions and
146/156 covered application statements. Final deliberate failure/restoration passed: 52/155 baseline, the intended
53rd failing case propagated exit 1, and the restored ./bin/verify --all passed
52 tests/155 assertions with 146/156 covered statements. Publication sanitation,
PR and hosted CI evidence are being collected. Raw logs,
source inventories, temporary probe changes and exact private identities remain
outside the public tree. The worker will not accept the phase or milestone.

Official sources: [Pest TIA](https://pestphp.com/docs/tia),
[Pest 5.3.2 dependencies](https://github.com/pestphp/pest/blob/v5.3.2/composer.json),
[Laravel plugin 5.0.1](https://github.com/pestphp/pest-plugin-laravel/blob/v5.0.1/composer.json).

The bundled Pest 5.3.2 JavaScript resolver cannot transform this starter's Vue
single-file components with standalone Rolldown: an observed direct invocation
failed parsing 12 Vue inputs. No vendor code or frontend dependency requirement
was changed. The trial extra Rolldown dependency was removed. Native Inertia
page bindings do select page tests: a temporary Welcome.vue edit selected only
ExampleTest; a temporary registration action failure selected RegistrationTest,
failed as intended, and passed after restoration.

Shared frontend safety is conservative: the wrapper fingerprints resources
outside the Inertia pages directory, plus Vite/TypeScript configuration and npm
manifests/lock. Any edit, addition or deletion forces --tia --fresh, recording
the complete suite instead of replaying a potentially incomplete JavaScript
graph. This includes shared Vue components, layouts, styles and Blade views.
Its branch-bound fingerprint metadata is ignored and required alongside the
complete Pest baseline; missing metadata refuses normally until explicit
recording. A Node regression covers shared edits/additions/deletions and page
selection. This is not a claim of transitive Vue graph support or selective
shared-component execution. Frontend lint/type/build and strict browser checks
remain independent required gates.

Final local TIA evidence: missing frontend baseline metadata refused with exit 2;
recording passed all 52 cases/155 assertions in 25.3 seconds wall time; unchanged
replay explicitly reported 52 replayed in 10.8 seconds. A shared InputError.vue
style edit forced fresh full recording of all 52 cases (57.5 seconds); restoring
it forced another fresh full recording and passed (25.6 seconds). These measured
local timings include setup and do not promise a general speedup. Both shared
probes were restored. The npm lock retains the prior approved package set and
exact bytes after removing the trial dependency. Dependabot's retained weekly
actions updates explicitly target dev, matching the PR workflow rule.

The publication snapshot is prepared for independent staged-file sanitation
review. Hosted CI remains PENDING_PUBLICATION as of this source snapshot; its
original run identity, conclusion and final PR status will be reported after
publication. These pending remote checks are not claimed as passing here.
M0-P2 remains unaccepted and no later phase has started.
