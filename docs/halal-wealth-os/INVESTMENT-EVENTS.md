# Investment event and book-cost contract

M3-P2 records investment facts. It does not execute orders, connect a broker,
infer ownership/compliance, supply ongoing prices or produce tax advice.
The exact canonical phase and original golden scenarios remain private.
Public examples use separately reviewed illustrative references and amounts.

## Identity, history and replay

An immutable investment event pins an owned portfolio account, native currency,
asset reference version and stable asset family. A metadata revision does not
create another cost pool. Financial input and JSON output use exact strings.
Each owner/request identity retains the complete payload fingerprint; identical
committed retry recovery precedes archive/current-state checks. A different
payload conflicts. A separate account/business-reference uniqueness rule prevents
a new request UUID from duplicating a statement or corporate action.

Replay starts with the latest eligible full opening statement and processes
effective UTC business date, immutable order and event ID. Within a date, original
facts use recording order; a replacement inherits the original order. This is
business-date recordkeeping, not inferred intraday execution time. Events cannot
precede their opening statement. Every affected holdings/receivable prefix must
remain valid, including after backdated corrections or opening revisions.

Corrections atomically cancel the old fact and cash at its original date, retain
both, and add a replacement at its explicit date. Standalone investment
cancellation also uses the original date. Saved reports remain immutable;
new historical reports use current retained correction evidence. A cancellation
that would leave unsupported sales or application allocations is rejected.
The opening editor edits original statement rows, never the replayed current book.

## Exact book cost

An equity or unitized fund uses one moving weighted-average cost pool per
account/asset family/native currency. Actual settled gross consideration is
authoritative; an optional recorded execution price is separate source evidence.
Purchase fees increase basis. Sale fees reduce proceeds. Dividend gross,
withholding, fees and net cash remain separately attributable. Standalone fees
are expenses without invented units. Other instrument models remain unsupported.

For a partial sale, allocate integer basis using the exact rational
`remaining basis × sold quantity / held quantity`, rounded half-up once to
native minor units. Quantity ratios use scaled integer arithmetic, not a rounded
average unit price. Final disposal consumes the exact remaining basis, including
all residual rounding. Units and prices retain up to 18 integer and 12 fractional
digits. Unsupported range or scale fails explicitly. A split preserves total
basis, changes no cash, and must produce an exactly representable quantity;
cash-in-lieu and silent fractional truncation are not supported.

Any unknown opening basis keeps the mixed pool unknown through acquisitions,
partial sales and splits. Its sale gains stay unavailable. Only full closure
resets the empty pool to known zero, allowing a subsequent independently known
acquisition. Known zero is distinct from unknown.

## Cash and IPO lifecycle

All investment facts use an existing linked investment ledger account in one
native currency. Funding from bank/cash is a separate explicit ledger transfer.
Goal backing accepts only bank/cash accounts; IPO earmarks accept only investment
accounts. The same account therefore cannot participate in both reservation pools.

IPO intention, earmark, release, paid receivable, confirmed allotment, recorded
refund and cancellation are separate facts. Intention/earmarks add no wealth or
shares. Payment moves investment cash into a face-value application receivable.
An allotment requires explicitly confirmed actual units and consumes only its
recorded allocated cost. Refunds consume the same remaining receivable and post
cash once. Partials may occur in either order, within paid capacity. Cancelling
an unpaid intention releases its earmark without inventing a refund; a paid
receivable must first be settled or validly corrected, never erased.

Available investment cash subtracts all active IPO earmarks. Payment consumes
its own earmark while preserving other applications' protection. New outflows
and earmarks cannot use future-date funding. Replay compares affected cash
prefix deficits before and after a change: external ledger spending can leave
an existing earmark underfunded, but cannot justify a new or worsened shortfall.
These comparisons include every actual ledger business date and the terminal
date, even when no outgoing investment event remains. A later deposit cannot
hide a historical deficit introduced by cancelling a spent dividend or refund.
Both comparisons retain the union of prior and candidate dates; moving or
cancelling a cashless fact cannot discard an earlier deficit checkpoint.
Relieving that retained shortfall remains possible. Cash uncertainty prevents
unsupported new spending. Holdings and receivable checks always replay in full.

The root event, exact idempotency receipt and any cash journal/link share one
owner-serialized PostgreSQL transaction. Detailed deferred guards verify cash
amount, date, ownership, currency, internal control roles and linked reversal
identity. A generic ledger correction/reversal cannot detach member cash from
its investment fact. Projections rebuild by reading retained evidence, without
reposting cash or depending on best-effort after-commit notifications.

## Reporting, uncertainty and retention

Detailed mode contributes signed cash, selected position values and outstanding
paid receivables once. Aggregate mode contributes its selected whole-account
value once and excludes all underlying cash, positions and receivables. New
investment facts invalidate an earlier aggregate value until its manual value
is reviewed again. Mode-preview fingerprints include every retained event
identity, including cashless changes and cancellations.

Execution prices, IPO allocated cost and book basis are not current quotes.
A unit-changing event invalidates the affected opening price reference;
current valuation remains explicitly incomplete pending later valuation rules.
Account-wide display totals outside the supported money range are unavailable;
individual receipts and complete exact authenticated exports remain retained.
Presentation pages do not truncate replay, totals or exports.

Financial events and links cannot be edited or deleted. Populated event-schema
downgrades refuse before DDL. Empty-schema migration checks preserve the accepted
opening and journal evidence. No M3-P3 valuation/performance engine, broker
execution, margin/options, tax filing, main/stage promotion or deployment is
authorized by this contract.
