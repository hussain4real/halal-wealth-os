# M4-P3 implementation and verification

Current local engineering: READY_FOR_REVIEW after cycle 6 of six. Publication
is in progress, and the phase remains unaccepted. The initial claim below and
all cycle results are retained. The human owner accepted
M4-P2 and authorized only M4-P3 through implementation, independent source and
rendered review, a sanitized PR to dev, normal noreply merge after exact-head CI
and fresh review, and original dev-push CI. M4 acceptance remains separate.

Verified baseline: 713d94a19c1aa380c5a159c57e85d2b3393b14f8. The original M4-P2
post-merge audit failure and the separately reviewed PR #15 remediation remain
in [the acceptance record](M4-P2-acceptance.md). Earlier phase histories are
preserved. One implementation writer owns this phase; reviewers are read-only.

The original private phase, execution prompt and acceptance fixtures were
inspected. Public specifications and tests paraphrase the requirements and use
separate illustrative names and amounts. The optional original example stays
outside the public checkout.

Local runtime: PHP 8.5.6 with PCOV, Node 24.15.0, locked Vite+/core 1.3.0 and a
new exclusively owned socket-only PostgreSQL 18.4 test cluster. The installed
native PostgreSQL differs from hosted CI's pinned 18.6. No existing database or
Mac checkout is used. Hosted CI remains a separate required exact-runtime gate.

Current checks: implementation, domain/integration/concurrency, full uncached,
TIA, rendered review, publication and hosted gates are NOT_RUN. No passing
engineering result or owner phase acceptance is implied by this initial record.

## Cycle 1 - initial implementation

The guarded native migration create/rollback/reapply check passed. The first
32-case domain pass had 22 passes and ten errors: nine reached a PL/pgSQL
record/JSON alias collision and one investment setup reached a stale-preview guard. The initial
hypothesis concerned fixture funding; the common UTC runtime cause was later
proven during cycle 6. These were retained as failures; no full-suite success is
claimed. Fixes in cycle 2 use distinct trigger record names; the synthetic actual-event setup omits an unnecessary mode
selection without changing existing investment assertions. Both TypeScript checks
passed. Sandboxed database and PHPStan probes were blocked by the safe socket
allowlist/process restrictions; subsequent checks use the existing allowed
private development socket and approved execution rather than weakening guards.

## Cycle 2 - trigger correction and broader behavior checks

The PL/pgSQL collision is resolved. The 50-case focused pass recorded 44
passes, five failed assertions and one setup error. Three HTTP assertions used
an old asset manifest without the new pages; the build is required before those
checks. Other fixture corrections compare the same account posting scope, use
an invalid currency for a raw-insert rejection (a new unassigned USD root is
valid), and omit unsupported tax on a purchase (tax deductions belong to
recorded distributions). No production contract or existing assertion is
weakened. Static analysis identified two CLI boundary casts and missing precise
numeric return annotations; cycle 3 validates arguments and carries the exact
money type. TypeScript passed again; formatter/linter passed after formatting.

## Cycle 3 - focused pass and full-gate preflight

All 52 focused cases passed with 358 assertions. PHPStan passed with zero
errors; TypeScript, lint, production build, dependency audits and migration
checks passed. The full gate then stopped at Pint argument spacing in a new
import test before executing the full regression suite. Independent source
review requested one P2 correction: complete exports must carry the actual
reservation/event facts needed to reconstruct their associations.

## Cycle 4 - complete export correction

The export now retains exact native source amounts, account/goal references,
release evidence, reversal/replacement IDs and current gross attribution with
original thesis IDs. IDs serialize as strings. UI copy explains that subsequent
sales/refunds remain separate investment facts and displays the concentration
reference source/validity. Full Pint formatted the new import test. The added
export check caught legacy source timestamps named created_at rather than the
new decision table's recorded_at (48 passes, one failed HTTP assertion and four
errors); the join and serializers were corrected together. The failed raw
report remains private and no complete-suite pass is claimed yet.

## Cycle 5 - corrected final behavior and engineering gates

Current status: running focused/full/TIA/rendered and affected source rereview.
These gates are not implied by prior focused successes. No M4-P3 or M4 owner
acceptance is recorded.

Cycle 5 focused behavior passed 53 cases and 363 assertions after timestamp
correction. Affected independent source rereview found an active-only export
path missing the reservation ID needed by cash backing; released rows had
bypassed that branch. Cycle 6 adds the exact source ID and proves active funded
export before release, then released/reversed reconstruction. No existing
financial or source assertion was relaxed. Cycle 6 is the final authorized
cycle; any unresolved new material gate failure requires a human checkpoint.

### Cycle 6 runtime correction

The first complete uncached run executed 802 cases: 690 passed, two failed and
110 errored (4,491 assertions). 109 errors were the identical stale portfolio
preview exception; the remaining error depended on the same failed preview.
The isolated native cluster's session timezone was Asia/Qatar, unlike UTC in
hosted CI. Legacy timestamp bindings interpreted preview expiry three hours
earlier. Only the exclusively owned disposable database default was changed to
UTC; no application code, old test, assertion or production setting changed.
The original failure report is retained privately. Unchanged portfolio HTTP and
concurrency suites and the complete gate are rerun to establish the runtime
correction independently. This is not an extra implementation cycle.

### Observed final local gates

- `./bin/verify --all` in the corrected UTC runtime: PASS, 802 tests and 5,318
  assertions, zero failures/errors/skips. JUnit identifies 749 predecessor cases
  and 53 new Decision cases. Coverage includes all 119 app PHP files,
  4,258/4,523 statements. Ten planning and 54 Node checks passed; Pint, PHPStan,
  TypeScript, frontend formatting/lint/build, lock audits and guarded migration
  fresh/rollback/reapply passed. Package locks, CI and protections are unchanged.
- External original private proposal: explicit opt-in import into an existing
  synthetic owner in a disposable rollback; exact original target/tranches,
  unverified offer evidence, pending Shariah and zero extra users, protection
  claims, trades or financial postings verified. No private values are published.
- Separate native TIA recording: PASS, 802 executed passing cases/5,322 assertions.
  Unchanged replay returned the 802 passing cached results/5,322 assertions;
  graph and frontend cache bytes remained identical. The native post-replay
  planner has empty changed/rerun sets and no unlocated reruns. The installed
  graph selects all four Decision suites for DecisionService and DecisionHttpTest
  for Form/Show. This is planner inspection with no source/cache mutation or
  separately executed impacted run; per-process replay counters were not exported. Four more assertions than the
  uncached run came from the permitted cash-withdrawal race branch.
  An initial private helper lacked Pest TestSuite initialization, was preserved,
  then corrected using the installed TestSuite and PHPUnit configuration builder.
- Independent source review: two P2 export findings were corrected and rereviewed
  READY_FOR_REVIEW. Exports now carry original source amounts, current backing,
  releases, reversal/replacement and original thesis IDs, including active claims.
  The independent full-report, TIA and privacy audit passed. The actual rendered
  review remains separate. The frozen application/test/config corpus has 576 files,
  with 126 compiled files; no mismatch was observed after all local commands.

Independent actual rendered review passed with no actionable finding. See
[the browser evidence](M4-P3-rendered-review.md). Five committed response-loss
recoveries retained exact payloads and request keys after stale or closed state.
The separate runtime was cleaned without touching coordinator resources.

Hosted exact-head CI, fresh review, merge and original dev-push CI remain pending
at this source checkpoint. See [publication gates](M4-P3-publication.md).
M4-P3 and M4 remain unaccepted. Inherited low UX-M3-01 is outside phase scope.
