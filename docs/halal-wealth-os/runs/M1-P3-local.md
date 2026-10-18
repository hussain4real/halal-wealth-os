# M1-P3 implementation and verification

Status: READY_FOR_REVIEW, unaccepted. Two of at most six scoped implementation cycles
used. The owner accepted M1-P2 and authorized this phase through implementation,
independent source/rendered review, sanitized draft PR to dev, exact-head CI and
normal merge, followed by independent M1 review. The sole coordinator preserves
the accepted baseline 8a4b5719ffab5aafb8916fe0586e9a20e7e08917 and all prior history.
No M2, main/stage promotion or deployment is authorized.

## Delivered behavior

Paired FX retains actual cash, declared transaction-rate source/time and explicit
fees. Each currency balances separately through clearing, which is excluded from
wealth. Group reversal/correction preserves both principal journals and any fee
atomically. Versioned manual reporting observations remain distinct from actual
transaction evidence. Native/base reports explicitly identify missing, stale,
partial and unavailable components. Saved reports retain evidence; reconciliation
compares native statements and permits explicit attributed equity corrections
with linked results and normal reversal history.

Read [the calculation, snapshot and time policies](../FX-AND-RECONCILIATION.md).
The UI retains exact strings and request identity after uncertain delivery; it
links constituent receipts back to the complete conversion. This is manual
synthetic bookkeeping, with no live rate provider or bank access.

## Acceptance mapping

| Requirement  | Evidence                                                                                                                                                             |
| ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1-P3-AC1    | ManualFxTest paired principal/direction assertions; real-commit FxConcurrencyTest rollback/guard cases; private canonical F04 passed separately with original inputs |
| M1-P3-AC2    | Both explicit fee sides, zero/excess fees, expense-kind count and clearing exclusion assertions                                                                      |
| M1-P3-AC3    | Direct-only selection, separate transaction purpose, missing/partial/future/stale boundary tests and native values retained                                          |
| M1-P3-AC4    | Immutable observation versions and saved report bytes; pair/purpose/fork restrictions; historical transaction evidence retained                                      |
| M1-P3-AC5    | Matching/difference/unavailable native comparisons; explicit attributed adjustment, linked zero result, reversal, stale-ID rejection and exact oversized difference  |
| INV-01/03/11 | Real-commit paired constraints, four independent-backend races, complete rollback/correction, outer-commit events and committed listener-failure root recovery       |
| INV-02/08    | Exact final rounding/range and KWD/JPY precision, explicit provenance/cutoffs and stale/missing handling                                                             |
| INV-04       | Principal is adjustment activity; explicit expense once; internal clearing excluded from reporting                                                                   |
| INV-10       | Actual authenticated foreign-ID HTTP denials, including admin and known request identities                                                                           |
| INV-12       | Full, browser, private fixture and publication checks are separate observed gates, never inferred from this mapping                                                  |

Reservation, investment, Shariah, integration and live-model invariants are future
scope and are not claimed as implemented verification.

## Observed checkpoints

Cycle1 implemented the schema, service and UI contracts. The first migration
parse failure was retained and corrected; the focused migration test then passed.
Initial domain verification passed87 cases/519 assertions including the prior
PostingService suite. Initial static analysis found type/documentation gaps,
which were corrected without suppression. Initial HTTP rendering preceded a
fresh frontend build and exposed missing generated assets; the built candidate
then passed actual HTTP rendering.

Cycle2 independent source review identified oversized/malformed ID handling and
the retained-data downgrade boundary. Controlled ID validation now rejects these
before PostgreSQL queries. Empty schema rollback/remigration is supported;
populated M1-P3 downgrade is explicitly refused before DDL. The combined new
suites passed75 cases/535 assertions, exit0; PHPStan passed. The later upgrade-preservation and observation-only downgrade guard cases also
passed in the final complete run.

Rendered review identified error-summary focus taking precedence over the
invalid field and native datetime controls omitting zero seconds. The shared
feedback helper now selects the invalid control first; UTC serialization adds
explicit seconds for minute-only native values. Report preview uses form-bound
validation feedback. The corrected candidate built successfully; independent browser re-verification
and the final uncached/TIA gates passed as recorded below.

## Remaining gates and limits

Final `./bin/verify --all` passed, exit0, in155.13 seconds:300 tests/1,830
assertions, no failures/errors/skips,1,047/1,132 covered application statements.
It includes all223 prior accepted tests plus77 new cases/550 assertions. The
full gate passed all16 Node harness/display checks, PHP8.5 platform/locks,
dependency advisory gates, guarded PostgreSQL migration smoke, frontend lint/
types/build, Pint, PHPStan, required discovery and actual coverage reports.
FxService covered99/104 statements, FxMath20/21, FinancialSnapshot63/70,
ReconciliationService52/58 and PostingService179/189. This is measured coverage,
not a claim of satisfying the later M5 multi-service release threshold.

Private canonical F04 ran with its original inputs and passed five checks:
directed actual cash/rate, balanced principal/fee journals, clearing exclusion
and one expense, immutable transaction/saved valuation evidence, and a matching
native statement. Public tests retain different anonymous synthetic values.

Independent [source review](M1-P3-independent-review.md) passed with both findings
closed. Independent [rendered review](M1-P3-visual-review.md) passed36 distinct
checks across34 inspected images, with four observed issues corrected and
rechecked. Browser/server stopped and its synthetic database was reset before
this final gate. Fresh Pest TIA recording passed300 tests/1,830 assertions in112.62 seconds. The
unchanged run passed with300 explicitly replayed results in4.25 seconds
(native2.27 seconds); these cached results are not newly executed tests. A native
planner probe discovered untracked FX source and, with a simulated earlier hash,
selected ManualFxTest, FxConcurrencyTest and LedgerHttpTest without modifying
source/cache or claiming actual impacted execution. Shared Vue graph selectivity
is not claimed; frontend changes still force full recording through the wrapper.

Exact candidate/index/HEAD review, hosted CI/review, normal dev merge and original
post-merge CI remain distinct pending gates at this checkpoint. Phase and M1 acceptance remain human-only.

Retrospective UTC business-date reports do not claim knowledge of past commit
visibility. The implied rate display is approximate when necessary; cash and its
exact ratio remain retained. In-process after-commit events are best effort;
missing delivery never justifies reposting. A durable outbox is future scope.
No full WCAG certification, screen-reader or cross-browser result is implied by
rendered Chromium checks. No native Goal activation, real user provisioning,
financial integration, credential/security change or deployment is claimed.
