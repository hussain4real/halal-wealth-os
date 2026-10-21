# Independent M4 milestone review

Disposition: **READY_FOR_OWNER**. No new blocking finding. Only the human owner can accept M4; M5 implementation remains behind that decision.

## Artifact and authority

Reviewed dev merge `7717c55820bfb56f9822f01c9f27008ff4cc5d37`, tree `3b3ff3a496fe047f0ab6067c514afa49c98d9b74`. Read the private canonical M4 review instruction, M4-P1/P2/P3 specifications and applicable original fixtures, plus AGENTS, runbook, architecture, invariants, validation, source and phase evidence. All three phases have separate human acceptance records; M4 was not self-accepted.

The only dirty paths were coordinator-owned AGENTS, STATE and the M4-P3 acceptance record. No application/test/configuration edits were made. An independent freeze covered 587 source/test/configuration/verification files and 126 compiled files. Hashes and modes matched after all checks and cleanup; no build file was added or missing.

## New independent verification

A newly owned, inspected socket-only PostgreSQL runtime used UTC, PHP 8.5.6/PCOV, Node 24.15.0 and PostgreSQL 18.4. Local versions are distinguished from pinned hosted versions.

The first `./bin/verify --all` stopped at formatting of the coordinator's newly edited STATE.json, before Pest. Its exit-1 result and raw log are preserved. After the coordinator corrected only that documentation and explicitly authorized a distinct corrective verification, the full command passed from 07:59:20Z to 08:00:48Z on 21 October 2026.

Actual JUnit/Clover independently confirm **802 tests, 5,322 assertions, zero failures/errors/skips**, including 177 Assessment/Purification/Decision cases and all other 625 regression cases. All 119 application PHP files were included, with **4,258/4,523 statements** covered. Ten planning and 54 additional Node checks passed, together with audits/platform checks, guarded migration fresh/rollback/reapply, formatting/lint, TypeScript, production build, Pint, PHPStan and report validation. Pest explicitly ran `--ci --no-tia --fail-on-empty-test-suite`.

Separate original canonical F12 and F13 production-service probes passed in rollback transactions. F12 established exact sourced obligation, no creation debit, one actual-payment debit, retry recovery and settlement without another debit. F13 established original exact tranche reconciliation, unknown evidence/pending Shariah and no financial writes. Rollback was verified; private original names and amounts are omitted.

## Criteria and invariants

All 15 acceptance criteria were checked against their canonical definitions, actual source and passing test bodies:

| Criteria      | Evidence                                                                                                                                                                                                                                                                          |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M4-P1-AC1–AC5 | AssessmentTest/HttpTest/ConcurrencyTest cover no-evidence pending state, separate source conflicts, overdue/missing dates, known-then immutable history and factual holdings/trades with every classification. Current exact-reference guidance and owner/admin negatives passed. |
| M4-P2-AC1–AC5 | PurificationMathTest and PurificationTest/HttpTest/ConcurrencyTest plus private F12 cover exact income-based amounts, obligation/payment separation, pending versus sourced zero, partial allocation/capacity, duplicate recovery and retained corrections/releases/reversals.    |
| M4-P3-AC1–AC5 | DecisionTest/HttpTest/ConcurrencyTest/ImportTest plus private F13 cover exact proposal totals, evidence uncertainty/freshness, shared protected cash, retained thesis versions and original actual-event attribution without another posting.                                     |

INV-01–INV-12 remain supported by the complete regression suite and inspected boundaries. Shared locks/SQL guards preserve capacity, exact arithmetic, owner isolation, immutable versions, retry recovery and retained-data migration refusal. Complete exports preserve source facts and history beyond bounded pages. Assessments, plans and obligation creation do not manufacture cash or holdings. Actual charity-payment recording uses the existing ledger port; actual-event linking never reposts an investment.

Unknown/conflicting/overdue evidence remains visible. No automatic ruling, default screening ratio or purification rate is supplied. No broker execution, actual charity transfer, zakat engine, live integration or M5 implementation was introduced.

## Retained evidence and limits

M4-P1/P2 rendered behavior was reviewed through committed independent reports; their older raw screenshot directories were unavailable and were not claimed reopened. For M4-P3, representative retained desktop/mobile screenshots, the independent browser summary, all five identical-request recovery records and unchanged financial audit arrays were inspected. No new browser session was run. Wider layout, keyboard and owner/admin observations remain attributed to the original reviewer.

Earlier separate TIA record/replay/native-selector and publication evidence remain distinct. No fresh TIA, hosted workflow or deployment check was run for this milestone. The reviewer previously independently verified M4-P3 exact-head review/CI, normal merge identity/noreply attribution and original post-merge CI attempt 1. No raw hosted XML claim is made. M4-P2's original post-merge audit failure and separate dependency remediation remain retained.

Inherited low **UX-M3-01** remains open and is not claimed fixed. Browser evidence is Chromium-only; no screen-reader, other-engine or production-usability claim is made. Later M5-P2 coverage/performance gates remain future requirements.

All **53 data tables** were confirmed empty before stopping only the reviewer's PostgreSQL process and removing its owned data/socket. Raw failures, logs, manifests, report copies, probe receipts and cleanup proof remain private outside the checkout. Source/build and the original Mac runtime were preserved.
