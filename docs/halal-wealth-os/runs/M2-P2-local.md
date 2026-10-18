# M2-P2 implementation and verification

Current status: ACCEPTED by the human owner at 11:19 UTC on 18 October 2026;
final record publication and M2-P3 are authorized. See [acceptance](M2-P2-acceptance.md).
The engineering record below preserves its historical status and cycle history.

Historical engineering checkpoint:

Status: READY_FOR_REVIEW, unaccepted. Four scoped cycles of at most six. The human
owner accepted M2-P1 and authorized this phase through independent source/rendered
review, sanitized draft PR to dev, normal merge after exact-head reviews/CI and
original post-merge CI. One coordinator writes implementation; reviewers are read-only.
M2-P2 and M2 remain human-unaccepted. No M2-P3, main/stage promotion or deployment.

The verified dev baseline is PR 6 merge
164e8901d81ef0d683bd4b784e9d2920e4c8353f with 378 passing tests and 2,423 assertions.
The prior four reviewed M2-P1 records were preserved privately before accurate
human acceptance updates and independently reviewed feature-branch publication.
No overlapping writer or active claim existed before the sole M2-P2 claim.

## Implemented acceptance evidence

| Acceptance | Observed evidence                                                                                                                                                                                                                      |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M2-P2-AC1  | Private original F05 passes six checks; reviewed anonymous regressions preserve the exact gap and 12-deposit invariant, with no inferred first date or essential coverage.                                                             |
| M2-P2-AC2  | An existing posted transfer changes dedicated backing once; idempotent whole-posting links leave journal/posting bytes unchanged and preserve reversal history. Reservations and links never post.                                     |
| M2-P2-AC3  | Actual distinct PostgreSQL backends wait behind observed barriers for same-key/capacity, cross-goal/cross-mode, withdrawal, account archive and release/create races. Outer rollback and deferred raw overcapacity guards are checked. |
| M2-P2-AC4  | FIFO cash withdrawal lowers actual backing while retaining nominal claims; restoration rebuilds it. Zero fixed contributions with a positive gap are unreachable; reached and variable states stay distinct.                           |
| M2-P2-AC5  | No/zero/unlike-currency essential input leaves coverage unavailable and the explicit target intact. Positive native run-rate matches the existing month-end policy with exact rounding.                                                |

See [bounded policy decisions](../GOALS-AND-PROTECTION.md). Configuration versions
retain account/mode/currency identity; closed goals claim zero current cash.
Opening backing references existing posted money. Dedicated accounts are exclusive
across both modes; reservations share capacity and retain full allocation/release
history. Current backing derives from signed authoritative ledger rows, without
an after-commit-event dependency. Goal funding history includes transfers and
reversals through its own read instead of budget economic-activity matching.

Projections use exact native money, nonnegative contribution policy, explicit
Qatar dates, anchored calendar addition and honest missed/variable/unknown states.
Standing orders are informational; effective reminders and delivery remain off.
No automatic transfers, inferred bank completion, interest or duplicate reminders.

## Verification checkpoints

The completed focused run passed 53 cases / 325 assertions, including the prior
budget concurrency suite. A subsequent six-case HTTP run passed 74 assertions,
including original-route revision retry after committed response loss. These are
overlapping focused sets, not added together as unique tests. Five new Node checks
cover scoped exact request drafts, confirmed clear, corrupted input and denied
session-storage fallback. Private original F05 passed separately.

A supporting synthetic profile used 301 journals and 24 goals sharing one account.
The first 20 visible goals used one balance aggregate and one account-wide FIFO
window; all preceding claims are counted before the visible-goal filter. Observed
47 queries and a single 101.55 ms index read are supporting local evidence, not a
canonical dashboard benchmark or future p95/SLO claim. Movements, reservations,
account choice, goal lists and retained version history use 20-row keyset pages;
complete totals do not depend on page size.

Independent [source review](M2-P2-independent-review.md) passed 28 frozen paths.
Independent [rendered review](M2-P2-visual-review.md) passed a broad 25-check /
13-image set and a separate 6-check / 3-image mobile set. All 16 screenshots were
individually inspected; the sets are not claimed as disjoint unique scenarios.
Fourteen rendered source hashes stayed unchanged, with zero JavaScript errors.
Real recovery covers creation, reservation and revision commit-response loss,
hard reload, same-identity retry, repeated clicks and Back-create. All findings closed.

The final uncached aggregate gate passed: **426 tests / 2,692 assertions**, zero
failures/errors/skips, with **1,798/1,923 application statements covered**.
The harness also passed 33 Node checks in total (10 planning-state and 23 other),
platform/lock/advisory checks, guarded migrations, formatting/static analysis,
frontend lint/types and production build. Wall time was 349.15 seconds; Pest
reported 247.39 seconds. All 28 reviewed source/test/harness hashes are unchanged.
Local TIA passed a fresh 426-test / 2,692-assertion recording (327.64 seconds),
then an explicit 426-result cached replay (4.08 seconds). Cached results are not
new test executions. Native planner probes simulated previous source hashes
without modifying source/cache: GoalService selects all four goal Feature suites;
the goal Show Vue page selects GoalHttpTest. No selective shared-Vue graph or
extra impacted-test execution is claimed. All 16 synthetic database table counts
were zero after guarded cleanup; independent browser resources were released.

## Preserved scoped cycles

1. Schema, exact backing/reservation services, projections, journal annotations,
   responsive UI and focused owner/money/concurrency tests. An initial FK shape
   mismatch and test fixture/build prerequisite errors were corrected and retained.
2. Independent findings corrected: pre-creation receipt correction high-water,
   original-route revision retry, stable lineage draft scopes, mode-specific
   shortfall text and reactive restored-attempt notices. The high-water regression
   actually failed before its fix and passed afterward. A reviewer temporary
   PostgreSQL initialization-port collision interrupted a focused run; the owned
   database socket was restored and affected checks rerun successfully. Corrected
   review initialization/cleanup verified the coordinator database stayed ready
   with unchanged socket identity. This resource incident is not an app pass/failure.
3. The first aggregate gate stopped at formatter comma spacing in two new test
   assertions. Exact formatting and the parallel formatter gate passed, with no
   behavioral change. The next aggregate attempt was interrupted by Composer's
   default 300-second timeout during uncached coverage execution; no completed
   aggregate report or pass was inferred.
4. Only the existing Composer process-timeout callback was added to test:coverage,
   permitting the complete suite to finish. Dependencies/locks, tests, assertions,
   coverage, report discovery and failure propagation remain unchanged. Independent
   review approved the exact 28-path freeze. The complete uncached aggregate gate
   subsequently passed; local TIA and publication gates remain separate.

Prior records and real failed/interrupted evidence remain retained privately;
cycle history is never reset. Publication and human acceptance remain separate.

## Final engineering handoff

[PR 7](https://github.com/hussain4real/halal-wealth-os/pull/7) merged normally
into dev at d1371f01fbc3e76743ff4d32668c4982bcc82f6b, with the exact reviewed source tree
7bc759a17febf0e1c9b750265d98eba828a51edd. The original PR CI and original dev-push CI both
passed on attempt 1; actual baseline, deliberate-failure and restored-baseline
logs were checked. Exact-head hosted review completed without findings, and a
fresh independent source confirmation preceded merge. See
[publication evidence](M2-P2-publication.md).

The sole claim is closed with four scoped cycles preserved. M2-P2 remains
READY_FOR_REVIEW and human-unaccepted; M2 remains unaccepted. Four final status
records are local/unpublished for owner review. No implementation change is
pending; M2-P3, main/stage promotion and deployment have not started.
