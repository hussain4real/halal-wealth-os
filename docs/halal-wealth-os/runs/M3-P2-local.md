# M3-P2 implementation and verification

Status: READY_FOR_REVIEW, unaccepted. Cycle 5 of at most six. The human owner accepted
M3-P1 at 17:09 UTC on 18 October 2026 and authorized M3-P2 through independent
review, sanitized dev PR, normal merge after exact-head gates and original
post-merge CI. One coordinator owns implementation. Stop before M3-P3.

## Baseline and canonical requirements

Verified dev 08e43860d4760985a6a44d5a5db3a9c637893c63 and accepted PR 9.
Four final M3-P1 records are preserved and authorized for publication with
[acceptance](M3-P1-acceptance.md). Main/stage remain at their verified baseline.
The exact canonical phase, prompt and original F08/F09 examples were read from
the retained source kit. Public synthetic translations require independent
sanitation. No private fixture values or original source documents are published.

## Cycle 1 checkpoint

The initial checkpoint preceded implementation. Its full, TIA, final rendered
and hosted gates were NOT_RUN. The observed scoped evidence and retained
failures below supersede that initial checkpoint; M3-P2 remains unaccepted.

## Cycle 1 observed checks and cycle 2 corrections

The initial core run passed 19 cases and failed three IPO cases on strict
PostgreSQL integer/string arithmetic. After the explicit cast correction,
all 22 core/math cases passed with 82 assertions. Eleven actual PostgreSQL
commit/concurrency cases passed with 94 assertions, including durable retry,
competing sales/refunds, business-action duplication, cash-link rollback,
raw orphan/reversal guards and after-commit recovery. These are scoped gates,
not full-suite evidence.

Frontend lint/format and TypeScript checks passed. The initial HTTP run passed
five and failed four cases because new pages were absent from the existing Vite
manifest. A real production build passed; the rerun passed eight and exposed
one missing no-store export header. The header is corrected; rerun is pending.
Static analysis identified numeric-string contracts to normalize explicitly.
No ignored errors or reduced validation are authorized.

Independent preliminary review identified underfunded earmark relief (I01),
aggregate money display overflow (I03) and exact raw cash-member control/reversal
semantics (I04). Actual targeted tests reproduced all three; cycle 2 fixes are
under focused retest. The potential opening-editor duplication (I02) had already
been prevented in current source and its HTTP prefill regression passed; it was
withdrawn as a current finding. Original failures and review notes are retained.

See [the event contract](../INVESTMENT-EVENTS.md). Full uncached, private original
F08/F09/F10, TIA, independent final source/rendered and hosted publication gates
remain pending. M3-P2 is IN_PROGRESS and unaccepted.

The cycle 2 focused run first stopped before assertions on a PostgreSQL CASE
expression syntax error in the strengthened migration. After parenthesizing
the expression, all four regressions passed with 26 assertions. PHPStan now
passes with accurate numeric-string return metadata and explicit integer
normalization. A broader investment run passed 51 and failed one unit case
with 403 assertions: validation required an application container after another
suite tore it down. The test now declares its application TestCase; retest
is pending. No assertion was weakened. Additional cases cover shared earmark
contention, spent-refund cancellation, zero/three-decimal currencies, exact
large quantities and overflow rollback.

Original private F08/F09/F10 probes passed against the actual services in a
guarded PostgreSQL transaction that was rolled back. They verified exact
fee-inclusive basis/allocation/gain, paid receivable without assumed shares,
actual allotment plus refund, and split conservation with exact retry. Original
values and raw probe evidence remain outside the public checkout.

## Cycle 3: complete cash suffix checks

The first full uncached run completed platform, lock/audit, migration smoke,
frontend lint/types/build and discovery. It was deliberately stopped before
Pest execution after independent review found I05; no complete passing result
is claimed for that partial run. Actual RED tests confirmed cancellation of
income spent through a generic ledger expense could escape validation when
no negative investment event remained, including an earlier deficit hidden by
a later deposit. Replay now compares every actual ledger business date and
the terminal date, alongside each investment reservation/spend prefix.
Existing external shortfall relief remains possible, while new/worsened dated
deficits reject the entire linked operation.

A historical empty portfolio migration test also reproduced a dependency
error with the new event foreign keys. It now removes only the new empty
schema before its original down/up and restores that schema before projection;
all prior ledger/report assertions remain. Five targeted cases passed with
20 assertions, including both I05 reproductions, retained earmark relief,
spent-refund investment use and legacy migration. PHPStan passed. Additional
spent-refund/general-ledger and atomic correction cases extend the I05 check;
full uncached verification and fresh independent reviews are pending.

Review also identified a new business-date comparison edge: adding a partial
release on a previously empty date compared an existing shortfall against an
absent zero baseline. An actual RED reproduced it. Prior and candidate replay
now include the candidate date in both sets, so relief can reduce an existing
shortfall without permitting new reservations. Linked general-ledger receipts
now disclose their owning investment record, suppress unrelated correction/
reversal actions and redirect generic correction to the complete receipt.
New HTTP cases cover purchase, dividend and fee members. Final targeted and
full uncached results remain pending at this checkpoint.

The final cycle 3 targeted run passed 10 cases with 79 assertions; original
private scenarios passed again. The next full gate completed prerequisites,
frontend build and discovery, then was deliberately stopped before Pest when
independent review found the complementary original-date issue: moving or
cancelling a cashless relief fact could remove its original date from candidate
replay. Cycle 4 retains every prior checkpoint date in the candidate comparison,
in addition to new candidate dates. Actual regressions cover both correction
and standalone cancellation with later generic deposits. Neither interrupted
full run is represented as a passing full gate.

Cycle 4 observed GREEN: all 61 investment domain, HTTP, actual commit/concurrency
and book-cost math cases passed with 465 assertions. Independent source review
verified all 536 frozen source hashes and modes before and after review and
reported PASS with no remaining material finding. The original-date correction
and cancellation RED cases are retained separately. Complete uncached, rendered,
TIA and hosted gates are still pending; source approval does not replace them.

## Cycle 4 complete uncached gate

The complete phase gate passed: 580 tests, 3,775 assertions, zero failures,
errors or skips; 2,985 of 3,205 executable application statements covered.
Platform and lock checks, dependency/advisory checks, disposable migration
smoke, frontend lint/types/build, Pint, PHPStan, discovery and report validation
all passed. Wall time was 660.287 seconds; Pest execution was 552.33 seconds.
The 536-path source freeze remained unchanged. Raw output and XML reports are
retained privately. Financial coverage thresholds remain a later release gate.
Fresh TIA recording, unchanged replay, native planner probes and final rendered
inspection are still pending at this checkpoint.

## Acceptance mapping from observed checks

| Requirement | Observed evidence                                                                                                                                                                                                                                                      |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| T1, T3, AC4 | Immutable event types and linked cancellation/correction; actual independent PostgreSQL backends cover duplicate purchases/allotments, competing sales/refunds/earmarks, injected cash-link rollback, deferred exact journal guards and after-commit receipt recovery. |
| T2, AC1     | Original private F08 service probe passed; public synthetic fee-inclusive purchase and partial-sale cases preserve exact allocated cost, net proceeds, gain and remaining basis. Zero/three-decimal currencies and final residual allocation pass.                     |
| T4, AC2     | Original private F09 service probe passed; intention, earmark, paid receivable, confirmed units, partial allotment/refund and cancellation remain distinct. Detailed receivable is included once and aggregate excludes it.                                            |
| T1, T6, AC3 | Original private F10 probe and split/business-action retry cases pass; quantity doubles with conserved basis and no split-only gain or cash. Dividend gross, tax, fee and net cash remain separate.                                                                    |
| T5, AC5     | Whole-history replay, owner isolation, unknown-basis sales, oversized quantities, unsupported shorts, opening revisions, atomic historical corrections and dated cash-prefix regressions pass in the complete uncached suite.                                          |

The original golden inputs remain private. This mapping records technical
evidence, not human phase acceptance. Independent source review passed on the
unchanged source inventory; rendered, TIA and publication results are recorded
separately as they complete.

Independent [rendered review](M3-P2-rendered-review.md) passed 49 executed checks
and 36 individually inspected screenshots. No material visual finding or
JavaScript error remained. Source/build identity matched and owned resources
were cleaned up. Its precise case matrix remains private; backend concurrency
and multi-date cash regression evidence remain separate technical checks.

## Final local TIA evidence

Fresh recording executed all 580 tests with 3,775 assertions in 698.426 wall
seconds (693.95 Pest seconds). The unchanged application/test source replay
returned 580 cached results and zero newly executed tests in 16.442 wall seconds
(6.05 Pest seconds). The retained branch baseline is complete with 580 results.
Native planner probes using simulated previous hashes selected the three
investment suites for InvestmentService and the investment HTTP suite for
EventForm.vue. They changed no source/cache and did not execute impacted tests.
No shared Vue graph selectivity or fresh-test speedup is inferred. Full uncached
verification, frontend/build and rendered checks remain separate passing gates.

All local engineering gates passed within four of six cycles. Original failures,
interrupted broad runs and independent findings remain preserved above and in
private evidence. M3-P2 is READY_FOR_REVIEW and unaccepted. Publication sanitation,
exact-head hosted review/CI, normal dev merge, original post-merge CI and human
acceptance remain separate gates in the [publication record](M3-P2-publication.md).

## Cycle 5: hosted unknown-cash correction

The cycle 4 implementation was independently sanitized, verified as exact
committed blobs/modes/tree, and published in [dev PR 10](https://github.com/hussain4real/halal-wealth-os/pull/10).
Its original [CI attempt](https://github.com/hussain4real/halal-wealth-os/actions/runs/39155383244)
passed. Raw output independently checked the passing 580-test / 3,775-assertion
baseline, exactly one intended failure with 3,776 assertions, and restored
passing baseline with 2,985/3,205 covered statements. Hosted exact-head review
then found native cash unavailability incorrectly rejected neutral facts and
relief; the old head was not merged.

Three actual regression cases failed on that published source, then passed
with 38 assertions after the correction. Exact signed investment cash and
capacity exposure are now compared at every aligned old/new event prefix and
end-of-day checkpoint. Known baselines retain absolute deficit comparison;
unknown baselines allow only no-worse relative cash/exposure. Pending opening
cash remains unavailable, while neutral opening revisions, split, intention,
confirmed allotment, earmark relief and eligible incoming cash remain possible.
New spending, own-earmarked payments, reservations, removal/reduction of prior
receipts or relief, and adverse historical moves still reject atomically.

Expanded checks cover real out-of-range totals, positive/negative native-range
transitions, signed unclamped exposure, eligible outflow correction/reversal
and vanished cashless dates. The first broad investment run passed 66 cases
and failed the new HTTP case because it addressed the page's account data as
state. The corrected property path retains all financial assertions; that
HTTP case passed with 33 assertions. Private original golden probes passed
again. Static analysis passed after validating the numeric stable-order suffix.
Fresh complete uncached, independent source/rendered and TIA gates are in
progress; no fresh full PASS is claimed yet.

The cycle 5 independent source review passed against all 536 frozen source
hashes/modes. The focused rendered supplement passed nine checks with eight
individually inspected screenshots; the earlier broad 49-check/36-image review
remains historical. The first complete cycle 5 gate then failed with 473 tests
passing and 113 failing (2,912 assertions) after disposable PostgreSQL exhausted
its 512 MB tmpfs while writing WAL and shut down. Subsequent failures were
connection/recovery errors; this run is retained and is not a full PASS. The
same owned pinned container was restarted with its exact configuration, which
reinitializes its disposable tmpfs. Database identity and free space were
verified before rerunning the complete gate on unchanged source. No application,
trust, network or security configuration was changed for this recovery.

The recovered complete gate passed 586 tests / 3,856 assertions, with zero
failures, errors or skips and 3,001/3,218 covered application statements. All
51 Node checks, dependency/platform, migration, static, frontend and build gates
passed. Exact source and compiled inventories remained unchanged. Wall time was
411.569 seconds, including one explicitly recorded standard PostgreSQL CHECKPOINT
for the owned disposable database; Pest execution was 332.39 seconds. This is
application verification, not an unattended runtime or performance benchmark.
The checkpoint changes no application rows, assertions, configuration or
financial/durability policy. Independent review confirmed that distinction.
The original failed run, maintenance receipt, successful raw output and actual
XML reports remain retained. Before fresh TIA, the same disposable container was
reinitialized with unchanged configuration after confirming zero users, events
and journals; fresh TIA/replay/planner results remain pending at this checkpoint.

## Cycle 5 final local evidence

Fresh TIA recording executed all 586 tests / 3,856 assertions in 533.831 wall
seconds (530.16 Pest seconds). One standard PostgreSQL checkpoint was recorded
during this fresh recording, with configuration and durability unchanged; this
is not an unattended resource benchmark. Unchanged replay returned 586 cached
results and zero newly executed tests in 6.341 wall seconds (3.48 Pest seconds).
The complete branch baseline contains 586 results. Native planner probes using
simulated prior content hashes selected all three investment suites for the
PHP service and the investment HTTP suite for the Vue page. They changed no
source/cache and did not execute impacted tests. No shared Vue selectivity or
fresh execution speedup is claimed.

Actual full XML also identifies 67 passing investment/book-cost cases with
546 assertions; this is a subset of that full execution, not a separate scoped
rerun. All 536 source hashes/modes and 140 compiled hashes remained unchanged.
Complete uncached, source, rendered supplement and refreshed TIA gates now pass
within five of six cycles. Earlier failures and partial gates remain preserved.
M3-P2 is READY_FOR_REVIEW and unaccepted; corrected exact-head publication,
hosted CI/review, normal dev merge, original dev-push CI and owner acceptance
remain separate gates. No M3-P3 work is authorized.
