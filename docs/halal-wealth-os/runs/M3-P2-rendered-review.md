# M3-P2 independent rendered review

Status: PASS for rendered behavior and visual inspection. M3-P2 remains
unaccepted; technical review does not authorize M3-P3 or deployment.

An independent reviewer executed 49 browser checks across a broad run and a
focused follow-up. All 36 screenshots were individually opened and inspected.
Desktop and mobile views covered keyboard access, validation focus, dark mode,
reflow, empty/error states and financial uncertainty. No material visual finding
or JavaScript page error remained.

| Area                             | Observed result                                                                                                                                                                                                                                                                                                 |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Trades and book cost             | Fee-inclusive purchase, partial sale, two differently priced acquisitions, moving pooled basis and final-disposal residual conservation passed. Current replayed basis and missing quote evidence were inspected directly.                                                                                      |
| Splits and dividends             | Split quantity changes conserved basis, cash and gain; another request identity could not duplicate the action. Gross dividend, tax, fee and net receipt remained distinct.                                                                                                                                     |
| IPO lifecycle                    | Intention and earmark created no shares. Payment consumed its own fully backed earmark at zero free cash and created a paid receivable. Confirmed allotment and refund cleared the receivable. Paid and unpaid cancellation boundaries passed.                                                                  |
| Valuation and uncertainty        | Detailed paid receivable contributed once. Aggregate exclusion and fresh confirmation after new activity passed. Unknown opening basis and realized result stayed unavailable; execution price did not become a current quote.                                                                                  |
| Corrections and cash links       | Atomic correction retained original evidence and replay order, recomputed dependent basis, and preserved linked history. Invalid dependent cancellation rejected atomically. General-ledger receipts showed their owning investment record and routed corrections there.                                        |
| Recovery and repeated submission | An actual committed response loss followed by Back, reload and exact retry retained the same identity/payload and produced one event/journal. A before-commit abort with an off-page reference, validation response and corrected retry preserved exact strings. A pending repeated click produced one request. |
| Complete evidence and ownership  | History paging retained complete book totals and export; application totals remained visible. Search preserved unsaved values and selected references beyond the first page. Foreign account/export/nested receipt requests returned 404.                                                                       |

The reviewer used separate owned disposable PostgreSQL/browser resources.
Both runs returned their inspected tables to zero rows, stopped their servers
and removed only their own containers. The coordinator database remained ready
with its original socket identity. Source and build hashes remained unchanged:
536 source paths and executable modes, plus 140 compiled files, matched their
inventories. The review slot was released.

Screenshots, synthetic fixture details, raw browser logs and the case matrix
remain in private evidence. PostgreSQL concurrency and historical backend
regressions are separate checks in the [implementation run](M3-P2-local.md).
See the [investment event contract](../INVESTMENT-EVENTS.md).

## Cycle 5 unknown-cash supplement

The targeted supplement passed nine actual desktop/mobile checks with eight
individually inspected screenshots. It supplements the historical 49-check /
36-image review above, which was preserved rather than rerun. Pending and
out-of-range cash stayed visibly unavailable while neutral split retry recovery,
unchanged opening correction and eligible IPO release/allotment/refund/cancel
remained usable. An interrupted response followed by Back and reload retained
the same request payload/identity and produced one neutral event with no cash
journal. New fee and earmark requests were refused. Confirmed units/basis and
receivable/earmark conservation remained exact; only the refund posted cash.
No material product finding or JavaScript page error remained.

All 536 frozen source hashes/modes and 140 compiled asset hashes matched.
The reviewer's owned resources were cleaned up; the coordinator socket remained
unchanged during that review. An initial five-second login navigation timeout
occurred before financial checks, was preserved separately, and was corrected
only in the private harness wait. Its screenshot is excluded from the eight
successful-run images. Full and hosted gates remain separate.
