# M1-P3 implementation and verification

Status: READY_FOR_REVIEW, unaccepted; dev merge, original post-merge CI and independent M1 technical review passed. Three of at most six scoped implementation/review cycles
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

## Cycle2 gates and limits

The cycle2 `./bin/verify --all` passed, exit0, in155.13 seconds:300 tests/1,830
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

## Hosted review correction checkpoint

Cycle3 independent M1 preparation identified stale current-status prose in the
Money/account contract. It now distinguishes accepted M1-P1/P2, delivered but
unaccepted M1-P3, native account arithmetic and the separate coherent reporting
snapshot, and final FX settlement precision. The original PR head a25b80c3b3157fcfbd2dd226bd36af89bdc96d90 passed its original
hosted CI39103689107, including baseline/deliberate failure/restoration, before
hosted review identified one P1 in past-date FX corrections.

Six new ordinary/FX regressions reproduced intermediate-cutoff double counting
before correction. They passed after cancellation journals, including FX fees,
were assigned the original business date; replacement journals retain their
submitted date. Standalone reversals remain today and default retry fingerprints
are preserved. Retained earlier-policy linked corrections are explicitly native
unavailable within ambiguous cutoffs, without editing immutable journals. New
deferred guards require coherent FX reversal dates and original-date cancellation
for new correction roots. The targeted145-case/1,158-assertion run passed; refreshed
full/TIA/canonical/rendered/source/publication gates are recorded in the subsequent checkpoint.

## Cycle3 final verification

The refreshed full `./bin/verify --phase M1-P3` passed, exit0, in113.50 seconds:
310 tests/1,927 assertions, no failures/errors/skips and1,053/1,137 covered
application statements. All223 prior accepted cases remain;87 added cases cover
this phase, including10 historical-timing and deferred-commit regressions. The
full platform, lock/advisory, guarded migration,16 Node checks, lint/types/build,
Pint and PHPStan gates passed. Private canonical F04 again passed all five checks.

Independent source re-review closed all three requested follow-ups: the
uniformly wrong cancellation-date commit guard regression, current posting
contract and inclusive legacy cutoff wording. Focused rendered re-review passed
16 distinct checks with13 newly inspected screenshots, recorded separately from
the prior36 checks/34 images. It confirmed original-date cancellations including
fees, replacement cutoffs without duplicated cash, ordinary correction, saved
evidence stability, standalone reversal today, and legacy unavailability/recovery.
No unresolved financial source or rendered finding remains locally.

Fresh TIA recording passed310 tests/1,927 assertions in124.82 seconds. An
unchanged replay explicitly reused310 results in5.43 seconds (native2.93 seconds);
replay is not fresh test execution. The native planner probe again selected the
FX service suites using a simulated previous hash without source/cache mutation.
Exact-head publication evidence follows independently. The old
300-case hosted result does not prove this corrected candidate. Phase/milestone
acceptance, normal merge, original post-merge CI and independent M1 review remain
separate gates; M1-P3 is unaccepted.

## Final dev merge and independent M1 checkpoint

[PR #5](https://github.com/hussain4real/halal-wealth-os/pull/5) merged normally
as b0f54bfa60dc2cb866ad35b7f52447a1578f600d, preserving exact reviewed head
b3e456544f0ccebea782f3f8d95f9c3d4309aa2d and tree
cd7141a6969fafecd6f29bc1caa10ffd7bc76a7e. Corrected original PR CI39105496328
and original dev-push CI39105779693 passed attempt1, with actual baseline,
intended failure and restoration verified. Exact-head hosted review completed
without findings; the original P1 thread was answered and resolved.

The independent M1 reviewer independently rechecked merge/source/remote evidence,
then executed a fresh full310-test/1,927-assertion gate in124.10 seconds, with
zero failures/errors/skips and1,053/1,137 coverage. Private F04 passed five checks;
F02/F03 transfer/failure/retry probes also passed. Fresh desktop/360px Chromium
review passed18 distinct checks with10 inspected screenshots. There were no
JavaScript errors or unresolved application findings. Server/browser stopped,
owned disposable DB reset to empty, and all469 tracked source hashes remained
unchanged at the review freeze.

See [the publication record](M1-P3-publication.md) and
[independent milestone report](M1-independent-review.md). Formal M1 outcome is
CHANGES_REQUIRED solely because M1-P3 human acceptance is missing. Technical
verification is PASS. The residual stale posting-contract status header was
corrected in this separate local documentation checkpoint after source freeze.
These final records remain locally unpublished. Three implementation cycles are
retained; phase/milestone remain unaccepted, and no M2 is started.

## Subsequent owner decision

The owner explicitly accepted M1-P3 and M1 at07:19UTC on18 October2026 and
approved publishing these final records plus M2-P1 work. Earlier unaccepted/
CHANGES_REQUIRED statements describe their observed checkpoint. See
[M1-P3 acceptance](M1-P3-acceptance.md) and [M1 acceptance](M1-acceptance.md).
The accepted application source and original test evidence remain unchanged.
