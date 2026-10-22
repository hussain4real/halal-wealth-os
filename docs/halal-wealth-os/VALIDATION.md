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
Unimplemented-phase rejection historically targeted M2-P2. Browser evidence, private
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

## M2-P2 implemented checks

The phase harness requires GoalTest, GoalHttpTest, GoalConcurrencyTest,
GoalReadBoundaryTest and GoalProjectionTest alongside every prior suite. Exact
backing, cross-mode exclusivity, posted transfer annotations, FIFO shortfalls,
zero/variable/missed-date projections, essential month-end policy, owner isolation,
durable retry and retained migration guards are required. Actual independent
PostgreSQL backends contend for reservations, releases, posting and account
archiving. Client attempt recovery has separate Node and rendered browser checks.
Private original F05 and independently reviewed public synthetic translations
remain separate evidence. At that checkpoint, unimplemented-phase rejection
targeted M2-P3; the current implemented boundary is recorded below.

## M2-P3 implemented checks

The phase harness requires CashAvailabilityTest, CashAvailabilityHttpTest,
CashAvailabilityReadTest, CashAvailabilityConcurrencyTest and AvailabilityMathTest
alongside every prior suite. Signed liquid cash, actual protection, explicit
required designations and reservation-occurrence overlap remain distinct.
Uncached tests cover unpaid/early-paid/reversed obligations, full recurrence and
version cutovers, native-group FX rounding/cutoff/range, owner isolation,
settings retry and immutable what-if requests. Read totals remain complete
beyond bounded disclosure pages; real independent PostgreSQL backends verify
settings serialization and coherent cash/protection reads. Client recovery,
private original F07 and rendered review are separate observed gates.
That historical unimplemented-phase boundary was M3-P1. That historical checkpoint preceded the M3-P3 authority now recorded in AGENTS.md.

## M3-P1 implemented checks

At this historical checkpoint, unimplemented-phase rejection targeted M3-P2.

The harness requires PortfolioTest, PortfolioHttpTest, PortfolioBoundaryTest,
PortfolioConcurrencyTest and PortfolioMathTest alongside all prior suites.
PostgreSQL checks preserve exact fractional units/prices through storage, owner
display and authenticated JSON export. Detailed cash-plus-positions and selected
whole-account values are mutually exclusive; basis uncertainty is separate from
known zero. Confirmations require an owned, unexpired, unchanged reconciliation
preview. Real independent backends cover lock order, same-key receipts, competing
mode/opening versions, saved reporting and repeatable-read coherence. Actual
commit checks exercise deferred complete-book enforcement and empty-schema
downgrade/upgrade without changing existing ledger evidence. Destructive
downgrades with retained portfolio data refuse before DDL. Browser, TIA and
independent publication evidence remain separate gates.

## M3-P2 implemented checks

At the M3-P2 checkpoint, unimplemented-phase rejection targeted M3-P3. The harness requires
InvestmentTest, InvestmentHttpTest, InvestmentConcurrencyTest and BookCostTest
alongside all prior suites. Exact moving weighted-average minor allocation,
final-disposal residue, unknown basis, stable dated replay, linked cash
cancellation/correction and whole-opening corrections remain mandatory.
IPO intent, shared investment-cash earmarks, paid face-value receivable,
confirmed allotment, refund and cancellation retain separate evidence.
Actual independent PostgreSQL backends test duplicate purchases/allotments,
overselling, shared earmarks and competing refunds. Actual commit checks cover
rollback, deferred exact cash-control/reversal semantics, after-commit receipt
recovery and retained migration guards. Authenticated complete export, owner
isolation, bounded disclosure with complete totals, native range/precision,
private original F08/F09/F10, uncached coverage, TIA and independent rendered
desktop/mobile recovery checks remain separate observed gates.

## M3-P3 implemented checks

Unimplemented-phase rejection now targets M4-P1. The harness requires
ValuationTest, ValuationHttpTest, ValuationConcurrencyTest and ValuationMathTest
alongside all prior suites. Exact dated raw prices preserve observation versions
and split-unit compatibility; server-issued retained-history context rejects
stale new price confirmation while committed retries recover. Native weighted
basis remains the event replay's truth; separate historical transaction-FX pools
include opening acquisition evidence and payment-date IPO allocation/refund FX.

Checks cover exact opposing native/base returns, partial prices, independent
unknown basis, inclusive price/FX cutoffs, historical corrections, owned scopes,
cache versions, immutable saved receipts, signed allocation denominators, common
component rounding, aggregate/detail exclusion and unsupported external flows.
Actual independent PostgreSQL backends contend for price/correction/run requests
and verify repeatable-read cache/input coherence. Retained migration refusal and
empty-schema roundtrip preserve prior evidence. Historical migration tests first
remove the later empty valuation schema without changing earlier assertions.
Original private F11, actual desktop/mobile UX, complete uncached coverage, fresh
TIA/replay and exact-head publication/hosted CI remain separate observed gates.
See [valuation policy](VALUATION.md) and [phase evidence](runs/M3-P3-local.md).

## M4-P1 verification contract

Unimplemented-phase rejection now targets M4-P2. The harness requires
AssessmentTest, AssessmentHttpTest and AssessmentConcurrencyTest alongside every
prior implemented suite. Check no-evidence pending state, separate source claims
and personal/AI notes, independent conflicts, absent/overdue review dates, exact
asset reference/family links, retained as-of author/evidence and trusted recorded
cutoffs. Real PostgreSQL backends cover duplicate requests, competing successors,
independent source conflicts and coherent reads. Immutable updates/deletions,
retained downgrade refusal and empty migration roundtrip require actual commits.

Verify owner/admin isolation on every read/write/history/export, forged-field
rejection, stale-context recovery and changed retries. Assessment changes must
preserve ledger, cash, units, cost and saved valuations; existing facts remain
recordable for every classification. Long evidence draft recovery has separate
Node checks. Fresh rendered desktop/mobile/keyboard/error/recovery review, full
uncached coverage, independently recorded TIA and exact-head publication/hosted
CI are separate gates. This contract does not claim they have passed; see the
[phase run](runs/M4-P1-local.md) for observed results.

## M4-P2 verification contract

The harness requires PurificationMathTest, PurificationTest, PurificationHttpTest
and PurificationConcurrencyTest with all prior suites; unimplemented rejection
now targets M4-P3. Exact sourced native income bases, pending versus sourced zero,
versioned corrections, actual cash debit once, existing-journal linkage, partial
allocation, full historical capacity, releases, generic ledger reversals, owner
and admin boundaries and lost-response recovery require observed evidence.
Real independent PostgreSQL backends contend for duplicate requests, revisions,
actual payments, payment capacity, outstanding and reversal; coherent reads,
immutable committed records and retained downgrade refusal remain mandatory.
Historical migration checks remove only the new empty successor schema before
retaining every original financial assertion. Client retry scopes, actual
desktop/mobile/browser recovery, full uncached coverage, separate fresh TIA
record/replay/native selection and independent source/publication review are
separate gates, not implied by this contract.

## M4-P3 verification contract

The harness requires DecisionTest, DecisionHttpTest, DecisionConcurrencyTest and
DecisionImportTest alongside every previous suite; unimplemented-phase rejection
now targets M5-P1. Verify exact target/tranche reconciliation, unknown watchlist
terms, independent official evidence dates, configurable dated concentration
limits, retained thesis versions and exact asset references. Planning creates
no posting, reservation or shares. Explicit reservations use shared GoalService
capacity; dedicated protection, other goal claims, cross-version tranche claims
and later FIFO cash shortfalls remain visible and protected.

Use real competing PostgreSQL backends for duplicate requests, revisions,
tranche/cash contention and actual event links/reversal. Committed SQL guards,
immutable records, retained downgrade refusal and empty-schema roundtrip must
preserve all prior financial assertions. Verify owner/admin isolation, complete
source-fact exports, stale and response-loss retries, exact actual buy/paid-IPO
attribution without a second financial write, opt-in external private imports
and the private original proposal separately. Full uncached coverage, separate
TIA recording/replay, actual rendered desktop/mobile review and exact-head
hosted CI/fresh review/original dev-push CI are separate observed gates.

## M5-P1 implemented checks

The harness now requires DashboardSnapshotTest, DashboardHttpTest,
DashboardConcurrencyTest and DashboardRulesTest alongside the unchanged starter
DashboardTest. Exact native/base totals, selected valuation modes, source lists
beyond twenty rows, missing observations, retained bytes, owner isolation,
settings validation and deterministic rule episodes have direct checks.
Separate PostgreSQL backends exercise duplicate capture, competing settings,
alternate dismissal receipts and capture versus financial withdrawal. Committed
retention guards and empty-schema migration roundtrips remain required.

The currency-target parser has separate Node checks. Full uncached coverage,
separate native TIA record/replay/selection, rendered desktop/mobile recovery,
publication sanitation, hosted exact-head review/CI and original dev-push CI are
separate observed gates. The unimplemented-phase rejection now targets M5-P2.

Final affected Node checks include owner/operation-scoped pending request
inventory, malformed-draft rejection, confirmed-clear observation and listener
cleanup. Actual browser recovery must cover normal success and both committed
and pre-commit response loss when a dismissal episode is dismissed, absent or
beyond the first visible page. A historical snapshot diagnostic alone cannot
satisfy that current-view recovery gate.

An uncommitted current dismissal whose episode disappears must return definitive
no-write validation and visible feedback; the obsolete exact-request recovery
must clear without a receipt. Complementary coverage disables the rule after a
committed dismissal and still requires the original cached receipt. Foreign
historical record 404s remain mandatory. HTTP feedback checks carry the actual
session cookie across the validation redirect; browser checks verify the real
response rather than injecting errors into a page.

## M5-P2 implemented verification contract

`bin/verify --all` now includes a real native-HTTP/PostgreSQL manual journey,
module request/file/log/session boundaries and the seeded dashboard performance
gate. It discovers every implemented concrete financial source recursively and
requires at least 90% executable-line coverage per file from current Clover;
interfaces are explicitly declaration-only. Missing files, malformed metrics,
failed/skipped tests, stale source/JUnit-bound HTTP proof or unknown/missing
acceptance identities fail the command.

The configured map contains 85 implemented-phase ACs and all 12 invariants, with
exact executed class/name/file identities and explicitly scoped retained manual
evidence. Current browser/source reviews supplement those historical observations;
manual evidence cannot replace a configured missing test. F01-F16 are applicable;
F17/F18, future roadmap suites, providers, AI and deployment remain unimplemented.

The benchmark requires 10,000 actual posting legs, 100 positive holdings, five
warmups and 30 sequential authenticated dashboard requests; server nearest-rank
p95 is the 29th sorted duration and must be at most 1,000ms. Retain all samples,
query counts/times, exact facts, cache/runtime/hardware and slow-query plans; no
coverage driver runs in the measured server. Separate TIA recording/replay and
native PHP/page bindings never substitute for uncached tests or browser evidence.

Runtime support remains the 8.5 line, now with security floor 8.5.12. The previous
8.5.11 runtime records remain historical, not approved current serving runtimes.
Unimplemented-phase rejection now targets M5-P3. Required suites additionally
include financial coverage/acceptance/performance report failures and stale-session
read/write/export regressions. Original hosted failure-propagation CI and a fresh
exact-head review precede normal dev merge; original dev-push application CI and
external publishing are separately verified afterward. Phase/milestone acceptance
remains a human decision.

## M5-P3 implemented verification contract

The full lane adds complete owner export, authenticated encrypted archive/file
handling, real populated PostgreSQL dump/restore/reconciliation, production
readiness/2FA and populated downgrade-refusal cases. A fresh per-run identity and
source inventory bind the actual recovery producer; stale or focused receipts
cannot be relabeled as a current full run. Final release proof also binds current
JUnit, the built asset manifest and hashes, dependency locks, exact Git commit/
tree/dirty state and ordered migrations. Finalization and final acceptance reject tracked/untracked changes and require
the recorded commit/tree to equal current HEAD. Commit reviewed source before
the final full gate; focused editing tests cannot issue a passing release record.

The map extends through all five M5-P3 ACs, retaining all prior exact selectors,
fixtures, scoped manual evidence and financial floors. New artifacts are
`build/release-recovery.json` and `build/release-record.json`; CI uploads sanitized
reports only. The same pinned PostgreSQL 18 service supplies psql, pg_dump and
pg_restore clients. Archive corruption tests reject before plaintext output;
actual restore uses a fresh, owned target and checks restored triggers/sequences.
The rehearsal does not populate every business scenario or authorize production.

Native Pest TIA, new page bindings, full uncached gates, source/rendered review,
publication sanitation, latest-head hosted review/CI and original dev CI plus
actual signed progress publishing remain separate. The later M6-P1 contract below
advances the implemented boundary. No green report grants owner acceptance or permits deployment. See
`ops/release/` for configuration, backup/rollback procedures and owner handover.

## M6-P1 implemented verification contract

The full lane includes bounded CSV parsing/mapping, immutable private previews,
explicit identity and matching, selected-batch atomicity, exact request recovery,
real concurrent approvals/corrections, linked root reversals and safe CSV export.
Native HTTP uses real multipart uploads, cookies and CSRF; it checks no preview
financial writes, malformed selection rollback, once-only retry/reupload, foreign
owner/admin denial, formula-safe download and linked cancellation.

All five import relations are included in the strict owner-export v2 catalog and
populated encrypted PostgreSQL recovery. Recovery reconciles all 63 tables and
reopens the retained source, preview and correction receipt. Financial coverage
adds every concrete Imports file at the unchanged 90 percent floor. The map adds
all five M6-P1 criteria while retaining existing exact selectors and invariants.
F17 claims only executed synthetic statement reupload; provider-job and outbox
retry scenarios remain future. F18 remains future M7. Unimplemented-phase
rejection targets M6-P2. Full uncached gates, separate native TIA, failure
propagation, independent source/rendered review, fresh hosted review/CI and
original post-merge CI/publishing remain separate requirements.
