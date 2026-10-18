# Validation contract

The accepted M0-P1 baseline checked documentation, JSON, requirement IDs/dependencies, links, publication hygiene and diff hygiene. Application checks were NOT_APPLICABLE to that historical planning-only snapshot.

M0-P2 adds project-owned ./bin/verify --phase Mx-Py and ./bin/verify --all. They are not native Codex commands. They fail for missing prerequisites and do not establish success until the real checks execute. See the current phase run for observed outcomes.

## Historical M0-P1 publication checks

- Parse every planning JSON file.
- Confirm 8 milestones, 25 unique phases, 150 task IDs, 125 acceptance IDs and 12 invariant IDs.
- Verify all dependencies exist, the graph is acyclic and each phase follows its predecessor.
- Verify only M0-P1 has explicit human acceptance, all later phases/milestone gates remain unaccepted, active_run is null, bootstrap remains false and publication authority is limited to the final reviewed planning snapshot.
- Resolve every relative Markdown link within this checkout.
- Inspect every proposed file for personal financial values, credentials, private identifiers and raw source documents.
- Check whitespace, final newlines and git diff --check. Before the initial publication all files are untracked, so normal git diff alone cannot cover content; explicitly inspect untracked files too. After staging verify the exact index tree matches the reviewed inventory.
- Record exact command exits and content hashes in private local evidence.

## Later gates

M0-P2 must select locked tooling and create a real failure-propagating harness plus CI. Later checks cover domain boundaries, PostgreSQL atomicity/concurrency, ownership, missing-data cases, production build and disposable migration tests. Financial calculation coverage and performance gates are release criteria, not current evidence.

No cloud, external provider, live-model or deployment gate has run. Missing mandatory services are blockers, not passing tests.

## Reconciled canonical release requirements

The verified kit supplies 18 golden scenario families, F01-F18. The originals contain private profile examples and remain in owner-only source evidence. They are canonical calculation evidence, not an approved public fixture set. Before implementing a related domain phase, provide the authorized worker private source access and approve any synthetic public-fixture translation that preserves every required scenario and expected invariant. Never silently substitute invented amounts or weaken an assertion.

By M5-P2, financial calculation services require at least 90% executable line coverage: Money, Posting, FX conversion, Reservation, Goal projection, cash availability, cost basis, holdings projection, valuation and purification. Report exact included paths; a missing coverage driver or omitted service blocks the gate. Coverage never replaces monetary, concurrency, ownership or missing-data scenarios.

The M5-P2 warm-dashboard target is server p95 at most one second over 30 sequential runs, using 10,000 journal postings and 100 holdings with no live external calls. Record environment, fixture, warm-up, query count, measurements and cache state. This is a future acceptance target, not observed performance.

The later harness must fail for unknown phase IDs, zero required discovered tests, missing tools or unavailable required services. Full checks cover implemented modules and applicable prior accepted tests; unimplemented roadmap tests cannot be reported as passing. Use disposable PostgreSQL resources and never reset production/shared databases.

M6 adapters may be reviewed disabled with deterministic mocks, but live connectivity remains NOT_TESTED/BLOCKED without separate destination/account authorization and an observed smoke check. M7-P2 requires separately recorded live-model evaluation; mocked transport is not live answer-quality evidence.

## Current M0-P2 contract

The harness checks real required tools, PHP extensions/coverage, both lockfiles and installed dependencies before bootstrap validation. It runs locked-package/platform/advisory checks, guarded PostgreSQL migration smoke, bundled frontend lint/type/build commands, real starter tests and coverage reports. Both Feature and Unit suites must discover tests. Missing reports, skipped required tests and zero covered executable statements fail. Financial coverage thresholds remain future gates. The deliberate failure probe requires a passing baseline, the intended Pest failure and a restored passing baseline; a prerequisite failure cannot stand in for that evidence. The original hosted CI passed; current publication and follow-up checks are recorded in the publication run and PR.

## PHP 8.5 and Pest/TIA publication gate

Application PHP support is 8.5.x only (~8.5.0). Both runtime checks and Composer
platform/constraint checks reject earlier and future minor lines. Pest 5/Laravel 5
plugin and PHPUnit 13 preserve all existing test bodies, setup behavior, data
providers and database guards. Functional syntax is required by TIA.

Full verification and CI use Pest --ci --no-tia, with both Feature/Unit discovery,
uncached execution, real coverage, mandatory reports and no required skips.
The optional local TIA entry point requires its own complete baseline and the
same tool/coverage/database safety checks. Record baseline, unchanged replay,
meaningful PHP/Vue selection and final uncached evidence separately. Do not
classify cached results as newly executed tests or promise a speedup without
measuring it. TIA cannot replace frontend lint/types/build or browser checks.

Pest 5.3.2 cannot resolve this starter's shared Vue graph. The local wrapper
fingerprints shared frontend sources and configuration; changes force a fresh
complete TIA recording. Page bindings and PHP edges retain native selection.
Missing branch-bound frontend metadata refuses execution until explicit
recording. No shared-component selectivity is claimed.

## M1-P3 implemented checks

The phase harness now requires ManualFxTest, FxConcurrencyTest, LedgerHttpTest,
ReconciliationTest and FxMathTest in addition to every prior implemented suite.
Real PostgreSQL commits cover paired posting/reversal constraints, retry races,
listener failure recovery, shared snapshots and retained-data migration policy.
Unimplemented-phase rejection now targets M2-P2. Browser evidence, private
canonical scenarios and exact-head publication checks remain separate gates.

## M2-P1 implemented checks

The harness requires BudgetTest, BudgetHttpTest, BudgetConcurrencyTest,
BudgetReadBoundaryTest and BudgetCalendarTest alongside every prior suite. Planning tests cover independent
flags, forecast-only salary, configured cadence/provision start, UTC actual dates
and Qatar due dates, many-to-many exact allocations, historical corrections,
owner isolation, retries and real independent PostgreSQL backend contention.
The essential run-rate uses the explicitly labelled month-end policy. Historical
M1 migration tests target their own schema after addition of the planning schema.
Private original F06, rendered review and publication gates remain separate.
