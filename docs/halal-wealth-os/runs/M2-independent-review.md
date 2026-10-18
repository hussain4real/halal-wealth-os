# Independent M2 technical review

Current owner decision: M2-P3 and M2 were human accepted at 14:41 UTC on 18 October 2026. See [phase acceptance](M2-P3-acceptance.md) and [M2 acceptance](M2-acceptance.md). Earlier status and publication statements below are preserved historical checkpoints. Final records and acceptance are authorized for publication; no new push is claimed by this addendum. M3-P1 is separately authorized; M3-P2 remains unauthorized.

Status: READY_FOR_OWNER. The independent technical gate passes with no open material findings or required source corrections. Only the human owner can accept M2-P3 and M2; this report does not accept either or authorize M3.

The gate is that emergency-fund and cash-flow scenarios pass without double counting or treating forecasts as actual cash. M2-P1 and M2-P2 are owner accepted. The current explicit workflow authorized this technical verification after normal dev merge and original post-merge CI. The canonical individual-phase acceptance prerequisite is not treated as satisfied by this technical result; M2-P3 and M2 remain pending owner decisions.

## Reviewed identity

Reviewed dev merge: `6a10cff7a772dff0d88efcf5104d3c3f7d37b149`, tree `2a061c15e9814a43660e27b12f37023b227f4db1`, parents `d1371f01fbc3e76743ff4d32668c4982bcc82f6b` and corrected feature head `63d8136a11a290d2aee197bc84d4accb159dfdea`.

All 567 tracked files, bytes and modes matched before and after independent verification, with an unchanged clean worktree at handoff. The rebuilt production assets matched the corrected rendered review. Detailed fingerprints and raw evidence remain private.

## Acceptance coverage

| Acceptance | Reviewed behavior                                                                                                                            | Existing regression evidence                                                                                                                                                                                                                       |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M2-P1-AC1  | Independent normal/emergency flags; original private scenario preserved.                                                                     | `BudgetTest.php:26`                                                                                                                                                                                                                                |
| M2-P1-AC2  | Forecast salary changes no cash; matching annotates an already posted receipt.                                                               | `BudgetTest.php:39`, `CashAvailabilityTest.php:215`                                                                                                                                                                                                |
| M2-P1-AC3  | Configured cadence, Qatar dates and explicit separately labelled provisions.                                                                 | `BudgetTest.php:56`, `BudgetTest.php:167`, `BudgetCalendarTest.php:10`, `BudgetTest.php:190`                                                                                                                                                       |
| M2-P1-AC4  | Partial many-to-many allocations, exact capacities, releases/reversals and concurrency.                                                      | `BudgetTest.php:69`, `BudgetTest.php:95`, `BudgetConcurrencyTest.php:128`, `BudgetConcurrencyTest.php:152`                                                                                                                                         |
| M2-P1-AC5  | Future versions preserve historical occurrences and ledger values.                                                                           | `BudgetTest.php:116`, `BudgetTest.php:135`, `BudgetConcurrencyTest.php:142`, `BudgetTest.php:249`                                                                                                                                                  |
| M2-P2-AC1  | Actually backed opening produces the exact gap and twelve deposits, without inferred dates.                                                  | `GoalTest.php:34`, `GoalProjectionTest.php:10`                                                                                                                                                                                                     |
| M2-P2-AC2  | Transfers and posting links increase funding once without another debit.                                                                     | `GoalTest.php:42`, `GoalTest.php:133`                                                                                                                                                                                                              |
| M2-P2-AC3  | Shared cross-goal/cross-mode capacity remains exclusive under contention.                                                                    | `GoalConcurrencyTest.php:129`, `GoalConcurrencyTest.php:137`, `GoalConcurrencyTest.php:157`, `GoalConcurrencyTest.php:189`, `GoalConcurrencyTest.php:197`                                                                                          |
| M2-P2-AC4  | Withdrawals reduce actual backing; a positive gap with zero fixed contribution is unreachable.                                               | `GoalTest.php:57`, `GoalProjectionTest.php:14`, `GoalConcurrencyTest.php:150`                                                                                                                                                                      |
| M2-P2-AC5  | Coverage requires a positive same-currency essential denominator; target remains separate.                                                   | `GoalTest.php:112`, `GoalTest.php:125`, `GoalTest.php:161`                                                                                                                                                                                         |
| M2-P3-AC1  | Exact source-linked surplus and transparent components; original private scenario retained.                                                  | `CashAvailabilityTest.php:27`, `CashAvailabilityTest.php:190`                                                                                                                                                                                      |
| M2-P3-AC2  | Backed bill overlap, effective paid residuals and selected investment membership avoid duplicate deductions; future salary remains forecast. | `CashAvailabilityTest.php:37`, `CashAvailabilityTest.php:74`, `CashAvailabilityTest.php:102`, `CashAvailabilityTest.php:86`, `CashAvailabilityTest.php:182`, `AvailabilityMathTest.php:34`                                                         |
| M2-P3-AC3  | Signed cash/raw deficit remain visible; complete negative deployable is zero.                                                                | `CashAvailabilityTest.php:49`, `CashAvailabilityTest.php:141`                                                                                                                                                                                      |
| M2-P3-AC4  | All four scenario assumptions leave authoritative financial data unchanged.                                                                  | `CashAvailabilityHttpTest.php:36`, `CashAvailabilityHttpTest.php:47`, `CashAvailabilityHttpTest.php:56`                                                                                                                                            |
| M2-P3-AC5  | Missing inputs, FX, unavailable balances and range limits give an explicit incomplete result.                                                | `CashAvailabilityTest.php:55`, `CashAvailabilityReadTest.php:26`, `CashAvailabilityReadTest.php:75`, `CashAvailabilityReadTest.php:85`, `CashAvailabilityReadTest.php:126`, `CashAvailabilityReadTest.php:138`, `CashAvailabilityReadTest.php:174` |

All listed test bodies, including their configured datasets, were present and passed in the independent uncached rerun. Original canonical F05/F06/F07 values and raw private evidence remain outside the public repository; only their check identities and observed outcomes may be summarized.

## Financial gate and invariants

- INV-01/02: Preserve atomic balanced ledger behavior and exact, bounded, native-currency money. Planning annotations and reservations do not post journals.
- INV-03/11: Retain attributed versions, reversals and release history. Durable retries return one owned result; changed payloads conflict. Interrupted forms keep the original exact attempt.
- INV-04/05: Count posted transfers once. Derive current protection from actual available cash; keep nominal claims and high-water evidence separate. Enforce cross-goal and cross-mode capacity with real independent PostgreSQL contention and deferred guards.
- INV-07/08: Avoid duplicate protection/obligation/investment deductions. Cap converted overlap within its native-currency protection group. Retain coherent direct owner FX identities and cutoff; missing, stale and future data remain explicit. Portfolio valuation and security-price behavior are future scope.
- INV-10: Enforce owner isolation across writes, cached recovery, reads, searches, pagination and remembered selections, including administrator attempts.
- INV-12: Separate source inspection, actual uncached tests, cached TIA replay, rendered observations, original hosted CI and human approval.

INV-06 holdings/IPO and INV-09 Shariah modules are unimplemented and not applicable to this milestone. Their future requirements are not reported as passing.

## Independent execution

`./bin/verify --all` actually exited 0 in 326.70 seconds on the reviewed merged source, using the reviewer's isolated PostgreSQL 18.6 database. The application's disposable-database guard passed before any migration and before each private canonical probe.

| Check                                             | Independently observed result                                                                       |
| ------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Full uncached regression suite                    | 474 tests / 2,973 assertions; zero failures, errors or skips                                        |
| Application coverage                              | 2,133/2,279 executable statements across 86 application files                                       |
| M2 regression inclusion                           | 164 cases across all 15 Budget, Goal and Cash availability suites                                   |
| Node checks                                       | 41 passed: ten planning-state and 31 other checks                                                   |
| Platform, locked dependencies and advisory checks | Passed                                                                                              |
| Guarded migration roundtrip                       | Passed, including retained-history refusal and empty chronological rollback/remigration regressions |
| Frontend and PHP checks                           | Formatting, lint, types, production build, Pint and PHPStan passed                                  |
| Original private canonical F06                    | Three checks passed; exit 0                                                                         |
| Original private canonical F05                    | Six checks passed; exit 0                                                                           |
| Original private canonical F07                    | Seven checks passed; exit 0                                                                         |

The original corrected-head PR CI and original dev-push CI were independently inspected: each passed its baseline, deliberately failed the intended harness probe, and passed the restored baseline. These hosted runs remain separate from the reviewer's actual uncached rerun. The coordinator's fresh TIA recording executed 474 tests / 2,973 assertions; its explicit 474-result cached replay and native selection of seven PHP and two Vue suites were inspected separately. Cached replay is not counted as fresh execution.

Actual financial checks retain forecast salary outside current cash; matching and goal links never post twice; dedicated and reserved goals share real capacity; withdrawals reduce actual backing while nominal claims remain visible; effective paid allocations reduce only unpaid obligations; explicit reservation-occurrence overlap consumes protection once; and an investment target deducts only its additional unbacked amount. Signed cash and negative raw shortfalls are retained. Missing inputs, unlike native currencies without valid FX and out-of-range calculations remain explicit rather than becoming invented totals. All four scenario fields preserve authoritative financial data.

## Browser evidence and limits

Retained independent real-app evidence covers budgeting, goal protection and cash scenarios. Budget evidence preserves separate broad 35-check / 18-image, dark-control eight/four, pagination/recovery twenty/ten and layout ten/four runs. Goal evidence preserves a broad 25-check / 13-image run and separate six/three mobile run. Corrected cash evidence preserves a fresh broad 39-check / 15-image run and separate focused ten/six correction run. These are overlapping evidence sets; their counts are not summed as unique scenarios.

Observed flows include matching/release/reversal, multiple receipts, Back/create, committed-response loss and hard-reload retry, exact native amounts, owner refusal, validation focus, keyboard controls, desktop/mobile and light/dark states, paging retention, four pure scenario fields, reset/repeated requests and source/currency conflict refresh. Relevant latest Budget and Goal page/service sources match the reviewed implementation; corrected cash evidence matches its frozen source and production build. Financial snapshots remain unchanged during scenario requests.

The milestone reviewer inspected the retained reports and representative screenshots across all three phases; it did not run a fresh milestone browser session. Retained Chromium observations at the exercised desktop/mobile sizes are not complete screen-reader, WCAG or cross-browser certification.

## Safety, findings and scope

Verification used a separately identified disposable PostgreSQL instance with no network, no published ports and temporary data. The root database remained ready with the same socket identity before initialization, after initialization and after cleanup. All 26 reviewer-owned financial/identity tables were verified empty, and the reviewer container was removed. No application source, index, shared environment file, claim or remote state was changed.

A reviewer-only initialization readiness race stopped before application guards, migrations or tests. Its cleanup and a subsequent stale-owned-socket refusal are retained privately. The private launcher was corrected to wait for the final server process; the final guarded run passed. No application failure or passing test is inferred from those pre-test attempts.

No open material finding remains. The three hosted corrections retain failing reproductions and passing regressions for native-currency protection rounding, revised-family classification paging and exact final recurrence bounds.

No live bank/model/provider validation, deployment, public SaaS, new credential/security change, main/stage promotion or M3 work is included. Future financial coverage and dashboard performance thresholds are not claimed by this review.

Final technical verdict: READY_FOR_OWNER. Owner M2-P3 and M2 decisions remain pending. Stop before M3.
