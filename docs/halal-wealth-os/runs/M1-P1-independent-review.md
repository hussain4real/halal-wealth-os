# M1-P1 independent verification

Result: READY_FOR_REVIEW for the independently executed phase/full-regression and browser checks. No application findings or blockers observed. Human acceptance remains separate. The coordinator's required local Pest TIA record/replay is a distinct checkpoint and was pending when this database slot was released.

## Reviewed content and independence

Reviewed local application source on base HEAD `7fbf58536556bb7bb31d487420a4b254bb60068d`, with the uncommitted M1-P1 implementation and preserved foundation records. The independently captured before/after inventories contain 413 tracked and nonignored untracked source files, 1,542,907 bytes. Every file hash and HEAD matched after testing; no source paths changed during this session. Test-generated ignored build/runtime artifacts were permitted. The reviewer changed only private helper/report files outside the checkout.

Read applicable repository instructions, runbook, architecture, invariants, validation, accepted ADR directions, canonical M1-P1 requirements, the money/account contract, numeric primitives, account lifecycle/ownership paths, phase tests and verification harness. Root assigned one exclusive PostgreSQL/browser slot; no database tests overlapped with another writer.

## Executed commands and observations

`./bin/verify --phase M1-P1`, invoked through a private process-environment runner with PHP 8.5, the explicit disposable database marker and the separately inspected isolated PostgreSQL socket, exited 0 in 72.72 seconds. This implemented phase uses the full uncached regression gate; no second identical `--all` run was needed.

- PHP 8.5.11, Composer 2.10.3, Pest 5.3.2, Xdebug 3.5.3, Node 24.19.0, npm 11.9.0 and PostgreSQL client 18.6 were available. Coverage was enabled per process.
- Planning/dependency/link/state and harness/frontend unit checks passed: 10 planning-state cases plus 6 verification/display cases.
- Strict Composer validation, installed platform requirements and dependency-tree checks passed. Composer reported no vulnerability advisories; npm audit reported zero vulnerabilities.
- The selected disposable PostgreSQL container was independently re-inspected as running, network-isolated, without published ports, with a bounded 512 MiB tmpfs and the expected unprivileged identity. Actual connection/database verification ran before destructive migration smoke. Fresh migration, full rollback, remigration and migration status succeeded. The marker/name guard alone is not proof that an arbitrary resource is disposable; independent resource inspection remains required.
- Frontend lint/format checks, TypeScript checking and production build passed. Pint and PHPStan passed. Required Feature/Unit and M0/M1 phase-suite discovery passed.
- Uncached Pest execution passed **164 tests, 762 assertions, zero failures/errors/skips**. This contains **46 MoneyPrimitives cases/80 assertions**, **43 AccountLifecycle cases/401 assertions**, and the existing 75-case foundation regression suite.
- JUnit and Clover reports were produced and checked. Application coverage was **430/445 statements**. Money/Currency/ExactDecimal included **50/51 statements**; implemented Ledger primitives/adapters included **29/29 statements**. Interfaces contain no executable statements. This describes observed implemented paths, not future financial service coverage or later release-gate satisfaction.

The private browser command was `HWO_REVIEW_EXECUTION_ALLOWED=yes python3 browser-accounts.py --execute`. It exercised real local HTTP with persisted synthetic fixtures and Chromium, using the configured proxy and default certificate verification. No TLS verification bypass was supplied. It passed seven grouped checks:

1. Anonymous account access redirects to login.
2. Keyboard navigation reaches the hydrated Accounts page and owner-specific empty state.
3. QAR `0.001` is refused with a visible live error associated by `aria-describedby`, `aria-invalid` and `role=alert`.
4. Opening input `90071992547409.93` renders exactly; real Inertia JSON retains minor units `9007199254740993` and decimal amount as strings. The request remains `awaiting_posting`, with unavailable/null posted balance and null net-worth contribution.
5. Rename and archive preserve readable details and exact opening request after reload; archived accounts expose no rename/archive controls.
6. The archived account remains in its owner's list.
7. A second synthetic owner with explicit admin capability cannot list/read/rename/archive the first owner's account. Direct cross-owner GET and both PATCH paths return 404; retained data is unchanged.

There were zero browser page errors and zero failed requests. The local server was stopped and all exact synthetic fixture rows were deleted in foreign-key order. No fixture credentials were retained in reports. Three earlier private-helper diagnostics were retained: an old Dashboard-after-login assumption conflicted with Laravel's intended Accounts redirect; a raw Inertia request omitted its version header; then its extraction used the pre-v3 DOM location. Those probe assumptions were corrected from the actual route/installed Inertia v3 script representation. Application source and acceptance assertions were unchanged; the final complete pass includes the raw JSON type/value check.

## Acceptance mapping

| Requirement | Observed evidence                                                                                                                                                                                                                                                                                 |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1-P1-AC1   | Money primitive regression proves exact QAR 0.10 + 0.20 = 0.30 and large integer JSON strings; real PostgreSQL/Inertia/browser round-trip independently verifies the above-safe-integer opening request.                                                                                          |
| M1-P1-AC2   | Executed primitive and HTTP tests reject mixed-currency arithmetic, unsupported/lowercase codes and invalid exponent input. No conversion fallback exists.                                                                                                                                        |
| M1-P1-AC3   | Executed signed BIGINT boundaries, arithmetic overflow, exact-decimal NUMERIC(30,12) bounds/scale, signed half-up settlement and pre-persistence malformed/precision/range/JSON-number refusal tests. Browser confirms cash excess scale produces an accessible server error.                     |
| M1-P1-AC4   | Executed schema/response tests demonstrate no writable balance field and unavailable real posting source. Archive, immutable request, no-delete, restrictive owner foreign key and stale-model archive regression tests passed; browser independently confirms retained archived account/request. |
| M1-P1-AC5   | Executed two-owner route/write/admin-capability tests and real browser HTTP denial. Pure signed movement tests cover negative debt, positive liability credit and clearing exclusion; injected-reader response tests cover serialization.                                                         |

INV-02 exactness, INV-10 ownership and INV-12 observed evidence apply directly and have passing evidence. INV-03 is exercised only for account/request retention and no-delete boundaries; posted reversal/version semantics remain future work. Other financial invariants requiring postings, reservations, holdings, FX provenance or later domains are not applicable here and are not reported as passed.

## Scope and limits

The real binding deliberately returns unavailable balances because no posting source exists. Derived-sum/liability/clearing and injected-reader tests are synthetic contract evidence, not proof of real posted history, actual account net worth or production operation. Opening input saves immutable unposted intent through the deferred gateway; it does not create a transaction or affect balances. M1-P2 must supply balanced atomic posting, idempotency, reversals and actual posted-history integration. M1-P3 owns FX observations/conversion. No live financial data, production user, external account, deployment or external publication was created.

This session did not independently repeat the coordinator's earlier corrective red/green or run local Pest TIA; those are separate evidence records. No full fresh lockfile installation was repeated because lockfiles/dependencies were unchanged and the current installed locked graph/platform were checked. Advisory metadata and local browser checks do not establish production security or later live-integration readiness. No new native Goal Mode claim is made.
