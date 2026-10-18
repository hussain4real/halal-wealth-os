# M1-P1 local execution and evidence

Status: **READY_FOR_REVIEW**, not accepted. Recorded at 2026-10-17T19:21:15.626609+00:00.
One implementation coordinator on local feat/m1-p1-accounts-money, base HEAD
7fbf58536556bb7bb31d487420a4b254bb60068d, with the uncommitted M1-P1 delta and
preserved M0 documents. No native Goal Mode was activated or claimed.

The owner approved M0 acceptance and proceeding; the manager explicitly scoped
this run to workspace-only M1-P1. M0 acceptance is recorded in
[M0-acceptance.md](M0-acceptance.md). All six preexisting local M0 documents
were backed up and hash-verified privately before lifecycle updates. The
historical independent M0 report, M0-P3 acceptance and M0-P3 phase specification
remain intact. The stale architecture status/version wording is corrected.

## Implementation and boundaries

Read the [exact money/account contract](../MONEY-AND-ACCOUNTS.md) for chosen
currency exponents, signed BIGINT cash bounds, NUMERIC(30,12) decimal bounds,
strict input versus explicit half-up settlement, string serialization, account
lifecycle, debit-positive/credit-negative signs and internal account exclusion.
No dependency or lockfile change was needed; required BCMath provides exact
string operations under PHP8.5.

Bank/cash/investment/liability and internal clearing/equity/income/expense
accounts are owner scoped. Routes require auth, verified email and confirmed
production 2FA. Creation assigns the owner on the server; unknown/authority/
balance fields are rejected. Only active account names may change; owner, type
and currency are immutable. Archiving retains accounts and requests. There is
no account DELETE route or mutable balance column; owner FK restriction and a
friendly profile-deletion refusal prevent cascading account loss.

Opening input is an immutable unposted request through a deferred gateway,
atomically saved with metadata. It creates no posting, transaction, financial
balance or automatic future job. Real AccountBalanceReader remains unavailable
until an actual posting source is implemented. Unknown balances stay null,
never zero. Synthetic derivation/injected-reader tests prove the read-only
contract and signs, not real posted history or actual wealth. M1-P2 owns
balanced posting/idempotency/reversals and actual posted-history integration;
M1-P3 owns FX observations/conversions. Neither phase started.

## Bounded cycles and independent sessions

Two of at most six scoped cycles were used; all earlier phase/checkpoint
history remains preserved. Independent sessions had separate private outputs
and no implementation write authority. Acceptance/test design and ownership
review ran alongside implementation/checks. The independent verifier alone
held the inspected database/browser slot while root did not use it.

Cycle 1 implemented primitives, schema, ports, UI and 82 initial acceptance
cases. Initial targeted results were 69 pass / 13 page-response failures
because new pages were absent from the old Vite manifest; real asset build
resolved that prerequisite. Static analysis also identified numeric-string
contract annotations and immutable/mutable Carbon typing, corrected with real
validation/type contracts rather than ignores.

Independent ownership review found M1-OWN-01 (MEDIUM): a stale active model
could rename or create an opening request after another instance archived it.
Two actual regressions failed before correction. Cycle 2 locks the fresh owned
row through lifecycle writes, reauthorizes the locked record, and guards direct
model saves/opening requests against stale archived state. Both regressions
then passed (2 tests/4 assertions), and independent ownership re-review passed.
The reproduction is deterministic stale-instance evidence, not a live
multiprocess posting/concurrency test.

Additional independent test-design advice became permanent settlement-edge,
full BIGINT persistence, cancellation-order and metadata-rollback tests. A
production-2FA POST fixture initially omitted CSRF after switching out of test
mode; the fixture now supplies a real matching synthetic session token. No
CSRF middleware was disabled. All 89 new cases passed with 481 assertions.

## Observed commands

| Check                                                                  | Observed result                                                                                                                                                                                                           |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Targeted uncached Pest on MoneyPrimitivesTest and AccountLifecycleTest | 89 tests / 481 assertions, exit 0                                                                                                                                                                                         |
| Independent `./bin/verify --phase M1-P1`                               | Full uncached path, exit 0; 164 tests / 762 assertions, zero failures/errors/skips; 430/445 application statements                                                                                                        |
| Full-gate components                                                   | Planning/lifecycle/links, 16 Node tests, locks/platform/audits, inspected PostgreSQL fresh/rollback/remigrate, frontend lint/types/build, Pint, PHPStan, required suite discovery and fresh JUnit/Clover reports all pass |
| Implemented financial paths                                            | Money/Currency/ExactDecimal 50/51 statements; Ledger primitives/adapters 29/29. Interfaces have no executable statements; no future calculation service coverage is claimed                                               |
| Independent real HTTP/browser                                          | Seven grouped checks pass, zero page/request errors, normal TLS; exact string serialization/display, accessible precision refusal, owner isolation and archive retention; fixtures deleted/server stopped                 |
| `./bin/test-impact --record`                                           | Fresh complete 164 tests / 762 assertions, exit 0; 65.9 seconds wall time                                                                                                                                                 |
| Unchanged `./bin/test-impact`                                          | Explicit 164 replayed, exit 0; 5.3 seconds wall time; cached results are not fresh execution evidence                                                                                                                     |
| Native TIA discovery/planner probe                                     | New untracked Money source discovered; simulated previous content hash selects both MoneyPrimitivesTest and AccountLifecycleTest. No source/cache edit or impacted execution was claimed by this pure planner probe       |

The optional local TIA graph includes the new untracked money/account source
and tests, with a complete 164-result baseline bound to this local branch.
Shared frontend changes still force a fresh full recording; no transitive
shared-Vue selectivity claim is added. Full gates retain --ci --no-tia and
real coverage. The planner probe required native Pest context initialization;
its initial standalone invocation diagnostic is retained privately. No
application source was changed to exercise the planner.

The [independent verification report](M1-P1-independent-review.md) maps all five
ACs and details exact full/browser checks, helper diagnostics and limits. Its
TIA-pending statement records the time its database slot was released; the
subsequent coordinator TIA observations above complete that checkpoint.

## Acceptance mapping

| Criterion | Observed evidence                                                                                                                                                                                             |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1-P1-AC1 | Exact QAR0.10+0.20=0.30; integer/decimal strings above JavaScript safe range; both signed BIGINT extremes persist exactly; actual Inertia JSON/browser string/value checks                                    |
| M1-P1-AC2 | Mixed NGN/QAR arithmetic and unsupported/lowercase codes rejected; explicit exponent catalogue; no implicit conversion                                                                                        |
| M1-P1-AC3 | Strict non-numeric-string refusal, scale/overflow/arithmetic/settlement boundaries, NUMERIC(30,12) primitive bounds and no-account/no-intent persistence assertions on invalid requests                       |
| M1-P1-AC4 | No writable balance, derived read-only contract with absent real source; archived accounts and immutable unposted requests remain readable; hard deletion/profile cascade refused; stale-instance regressions |
| M1-P1-AC5 | Two-owner/admin-capability list/read/write denial in automated and real HTTP checks; signed debt/credit/overdraft and clearing exclusion via synthetic derivation                                             |

## Limits and stop condition

Posted-history retention cannot be integration-tested without future postings;
this phase retains existing account/request history and establishes restrictive
no-delete boundaries. Future quantity/price/FX database persistence does not
exist; exact representation/validation is tested without inventing those tables.
Other posting, reservation, holdings, FX provenance and later-domain invariants
remain not applicable, never passed. M1 remains unaccepted. No production, real
mail/provider, full accessibility certification or live financial behavior is
verified. Database disposability relies on separately inspected resource
ownership/isolation, not the marker/name alone.

The connection notification was checked against actual command execution, same
environment/host/HEAD and all 413 pre-final-report source hashes. Original TIA
execution was preserved to completion; no duplicate writer/test or replacement
was started. Confirmed recovery evidence and raw logs/reports remain private.

Stop READY_FOR_REVIEW with no unresolved application blocker. M1-P1 acceptance
is solely the human owner's decision. No commit, push, PR preparation/creation,
merge, deployment, external publication, real provisioning, persistent
credential or security-setting change occurred. All work stays local.
