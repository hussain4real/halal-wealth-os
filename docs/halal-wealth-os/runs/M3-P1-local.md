# M3-P1 implementation and verification

Status: READY_FOR_REVIEW, unaccepted. Cycle 3 of at most six. The human owner accepted
M2-P3/M2 at 14:41 UTC on 18 October 2026 and approved M3-P1 through implementation,
independent technical/rendered review, sanitized dev PR, normal merge after exact
head reviews/CI and original dev-push CI. Stop for owner phase acceptance before
M3-P2; no main/stage promotion or deployment.

The dev baseline is PR 8 merge 6a10cff7a772dff0d88efcf5104d3c3f7d37b149.
The five final M2 records were preserved byte for byte privately before recording
the owner's acceptance. Their reviewed nine-document acceptance/publication
candidate was committed and first published on the feature branch at
9f02a974647112e8c5a9c8a22001b2336496d48a. Independent exact committed-blob/mode
and public metadata checks passed. Application source was unchanged. Main/stage
remain 9f8db16620aabec70691538d82066eddc2d01e49.

Exact canonical tasks, acceptance criteria, prompt and shared financial rules
were read before implementation. Original source and raw evidence remain private.
New phase results below are observed; full, TIA, rendered and publication gates remain pending until recorded. Earlier phase tests,
failures, review history and cycle limits are preserved.

## Cycle history

1. Define exact opening evidence, owner-scoped asset/account relationships,
   valuation-mode exclusion, dated reference valuation and reconciliation
   preview/retry contracts. Implement only M3-P1 and map its five acceptance IDs
   to real tests and rendered evidence. Full checks, TIA, independent review and
   publication remain pending until observed.

## Cycle 1 observed implementation checks

The five scoped suites passed 45 tests / 328 assertions. They cover all five
acceptance contracts at domain/HTTP level, including exact 12-place unit/price
strings beyond JavaScript safe integers in display/export; known zero versus
unknown basis; mode round trips; nullable preview differences; aggregate values
including zero/negative without cash or position duplication; owner/admin and
nested-parent isolation; unchanged-receipt retries; expiry and UTC midnight within
the ten-minute expiry window; archived writes; immutable reference/book/report
evidence; raw SQL composite/version/scale/retention guards; complete totals and
exports beyond the 20-row disclosure page; unsupported/mixed-currency/over-limit
input refusal; and asset-only profile deletion retention.

Eight nonwrapped PostgreSQL checks use actual commits and independent backend
barriers: owner-before-account preview lock order, same-key confirmations,
competing baseline previews, complete-book correction races, serialized saved
reporting/mode decisions, repeatable-read capture during a committed correction,
deferred incomplete-book rollback and empty-schema upgrade preserving ledger and
saved report evidence. No SQLite substitute or required skips were used.

Early read-only review found E01 preview lock inversion and E02 legacy missing/
stale FX-state loss. E01 was reproduced with a real blocked PostgreSQL backend
(one failing lock probe), then passed after owner-first ordering. E02 preserves
the original reducer with explicit missing/stale regressions. E03 unconfirmed
portfolio accounts were incorrectly labelled legacy-ledger; the account payload
now distinguishes unconfirmed from absent portfolio configuration, with index
and detail HTTP assertions. Actual commits exposed E04, a deferred trigger CASE
resolving a nonexistent record field; explicit PL/pgSQL branches fixed it and the
complete-book concurrency/commit regressions pass. Original failures and exact
command logs remain private. A test-only archive setup initially used a guarded
mass-assignment field; it now uses the real model save path and asserts archiving
before testing the refusal. Assertions were not relaxed.

Static analysis passes. Initial HTTP page checks failed before the newly added
pages were present in the production manifest; rebuilding resolved them. All
eight screens pass TypeScript and the initial production build. Formatting-only
frontend gate findings are being corrected before the full uncached gate.

| Acceptance ID | Observed automated evidence                                                                               | Remaining independent evidence                 |
| ------------- | --------------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| M3-P1-AC1     | PortfolioTest, PortfolioHttpTest, PortfolioMathTest exact quantity/price/storage/display/export           | Rendered exact string inspection               |
| M3-P1-AC2     | PortfolioTest, PortfolioBoundaryTest, PortfolioConcurrencyTest mode exclusion and coherent reports        | Rendered detailed/aggregate report comparison  |
| M3-P1-AC3     | PortfolioTest, PortfolioHttpTest known-zero/unknown-null distinction                                      | Rendered labels and manual capture             |
| M3-P1-AC4     | PortfolioTest, PortfolioHttpTest, PortfolioConcurrencyTest owned preview/acknowledgement/stale/race/retry | Rendered cancel, confirm, failure and recovery |
| M3-P1-AC5     | HTTP strict-field rejection and retained source/type metadata                                             | Rendered platform/source neutrality            |

Full uncached baseline, TIA recording/replay/native selection, final independent
source/rendered review, public sanitation, PR, exact-head hosted CI/review and
normal dev merge/post-merge CI remain NOT_RUN. No phase acceptance is claimed.

## Cycle 1 full gate and independent review

The actual full uncached gate executed 519 cases: 517 passed, two failed,
3,294 assertions, no errors or skips. Coverage included 2,544/2,744 executable
statements. This is a failed gate, not release evidence. Both failures were
historical empty-schema downgrade tests whose fixed rollback counts stopped one
migration early after adding the portfolio schema. PHP/static/frontend checks
and the production build passed. The original XML and command logs are retained
privately.

Independent technical review requested E05 and actual rendered review reproduced
V01: an interrupted opening request involving a manual reference beyond the first
20 choices lost its display kind on canonical-page reload. Its exact retry safely
returned validation, but the unlocked form then invented required unit fields and
blocked correction of a manual value. The reviewer captured the actual failure
and verified the before-commit aborted request changed no authoritative tables.
All reviewed source/build bytes and the coordinator runtime were preserved.

## Cycle 2 correction under verification

Keep a bounded owner/account-scoped session cache of validated reference display
metadata for the selected opening rows. Restore the kind/name/version before
rendering recovered fields. This cache is separate from the exact six-field
financial attempt and its request identity; the server continues to resolve
authoritative owned assets. No quantities, prices or request hashes are rewritten.
The older downgrade tests now explicitly roll back the empty portfolio schema
and assert its absence before their original rollback sequences and retained-byte
assertions. Final scoped/full/TIA and independent rendered/source results remain
pending until actually observed.

## Cycle 2 final local evidence

The full uncached gate passed 519 tests / 3,305 assertions, with no failures,
errors or skips. Clover includes 2,544/2,744 executable application statements.
All 51 Node checks passed, alongside platform/lock/advisory checks, guarded
PostgreSQL migration smoke, formatting, static analysis, frontend lint, types and
the production build. Original prior failures remain in the cycle history.

Independent source review passed all 603 frozen files, closed E01-E05 and mapped
all five acceptance criteria to observed automated and rendered evidence. Actual
rendered review passed 33 broad desktop/mobile checks and 18 individually
inspected screenshots, plus one focused recovery retest and screenshot. These
are 34 observed checks with overlapping recovery coverage, not 34 unique
scenarios. See [the sanitized rendered report](M3-P1-rendered-review.md). V01 is
closed by actual RED/GREEN evidence; no material findings remain. Reviewers used
separate disposable resources, cleaned them and verified the coordinator runtime
and every frozen source/build byte unchanged.

Fresh Pest TIA recording executed 519 tests / 3,305 assertions and retained a
complete branch baseline. Its observed wall time was 662.21 seconds. The unchanged
replay took 13.09 seconds and replayed all 519 results; it executed no new tests.
Native planner probes using simulated prior content hashes selected all four
portfolio feature suites for the PHP service and PortfolioHttpTest for the
opening Vue page. No source/cache mutation or impacted-test execution is claimed
by those probes. Shared Vue/config changes still require a full fresh recording.
These are separate local observations; the full uncached gate remains the
release baseline. Exact original logs, XML, cache snapshots and hashes are private.

All six tasks and five acceptance contracts are implemented and locally
verified. The phase is not human accepted. Final sanitized candidate/PR text,
exact index/commit review, hosted exact-head CI/review and normal dev merge with
original post-merge CI remain pending publication checkpoints. Main/stage are
unchanged; no M3-P2 or deployment is authorized.

## Cycle 3 hosted-review checkpoint correction

PR [9](https://github.com/hussain4real/halal-wealth-os/pull/9) targets dev. Its
first reviewed source head was 47372d62bd9dadc3060d187d35e085ff1ad6b8d2. The
original attempt-1 [hosted run](https://github.com/hussain4real/halal-wealth-os/actions/runs/39144154216)
passed. Raw logs verify the 519-test / 3,305-assertion baseline, the intended
one-failure probe alongside 519 passing tests / 3,306 assertions, and the
restored 519-test / 3,305-assertion baseline, with 2,544/2,744 application
statements covered in both full gates.

Hosted review identified E06: the active-run checkpoint still contained the
initial cycle-1 NOT_RUN text despite the later observed gates. The current
checkpoint and cycle count now reflect the actual publication state. This
correction changes documentation only; validated application/test/frontend
bytes and all local full, TIA and rendered evidence remain unchanged. Planning
and lifecycle checks plus independent exact-document/commit review are required
before publication. Fresh hosted CI/review on the corrected head remains pending;
no merge, phase acceptance or later work is inferred from the first-head CI.
