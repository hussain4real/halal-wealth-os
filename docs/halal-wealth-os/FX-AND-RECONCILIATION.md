# Manual FX and reconciliation decisions

M1-P3 implementation contract, subject to independent verification. This document
records bounded implementation choices, not observed passing tests or phase acceptance.

## Exact money and observations

Cash retains signed BIGINT minor-unit strings. Rates use positive exact
NUMERIC(30,12), up to18 integer/12 fractional digits. Direct rate direction is
quote major units per base major unit. No reciprocal inference, triangulation or
live provider exists. Observations are owner-scoped immutable versions with source,
observed/effective UTC timestamps, actor and supersession reason. Reporting quotes
and transaction-rate evidence have separate purposes. Corrections append versions;
old references remain resolvable. Authoritative paid/received cash is retained;
entered transaction rate and the exact implied cash ratio are separately labelled.
The approximate implied-rate display truncates to12 places; the exact numerator
and denominator remain visible and authoritative cash is never recalculated.

Reporting uses exact bounded arbitrary-precision intermediate strings, including
19-digit JPY major amounts, and HALF_UP only at final target cash settlement.
It never routes a valid large cash intermediate through the smaller ExactDecimal
integer range. Final Money overflow is explicit unavailable, not truncation.

## Cutoffs and completeness

User timestamps and cutoffs are explicitly UTC. Effective-date journals have date
precision: all visible posted/reversed movements on or before the cutoff's UTC
business date are included. Reversed originals remain included; reversing legs
enter on their own effective dates. This is retrospective effective-date reporting
from the capture snapshot, not a claim of past commit visibility. posted_at is
not commit time. Later backdated records require a new saved comparison/report.

Reporting rates require both effective_at and observed_at at/before the cutoff.
Choose latest effective time, observed time, then observation ID, in that order.
A rate older than7 days by either timestamp is stale; exactly7 days is eligible.
Stale/missing rates contribute no converted value or complete total. Same currency
is an explicit identity case. Native amounts remain visible, covered subtotals
are labelled, and excluded components explain partial/unavailable results.

Combined native balances, opening completeness, included journal IDs and selected
reporting quotes come from one SQL snapshot. Saved evidence retains amounts,
observation IDs, cutoff and calculation policy; a new quote never rewrites it.
Unconsumed openings remain unavailable. A consumed opening outside a cutoff
cannot be silently treated as zero or made consumable again.

Retained corrections from the earlier policy can have a later cancellation date
than their original. The one-statement snapshot detects these linked records and
returns native unavailable with legacy_correction_timing for the affected date
window. It does not reinterpret or edit immutable journals. Cutoffs before that
window or on/after its end remain eligible; saved captures remain unchanged.
The calculation policy identifies this boundary explicitly.

## Atomic conversions and recovery

One owner lock/outer transaction commits the operation identity, transaction-rate
evidence, two separately balanced principal journals, optional fee journal and
immutable membership links. Clearing is provisioned lazily per owner/currency,
excluded from wealth. Fees explicitly select extra source outflow or withheld
destination cash; they are expense once, while principal is adjustment activity.
Generic constituent reversal is rejected and linked to the conversion receipt.
Full group reversal includes its recorded fee; correction reverses and replaces
the group atomically. Database deferred group checks prevent committed halves.
Correction cancellation journals, including fees, use the original operation's
business date; the replacement uses its separately submitted date, whether
before, equal to or after the original. Fresh reports therefore retain only the
replacement on its actual date. Recorded timestamps/reasons/links retain when
the correction was entered; saved evidence is unchanged. Standalone reversals
remain effective today. All reversal members share one business date, and new
FX correction roots require original-date cancellation at deferred commit.
MIN inversions retain the accepted MAX/+1 split behavior. Empty installations
can roll back the schema; once M1-P3 evidence exists, downgrade is explicitly
blocked before any DDL. Corrections then require forward migrations.

Keys are scoped by owner and root operation type; all consequential fields are
fingerprinted. Account/observation/group ownership precedes cached recovery.
Committed identity and journal links recover lost responses or listener failures;
missing best-effort after-commit events never justify reposting. There is no
durable outbox/exactly-once delivery guarantee.

## Reconciliation

Comparisons save native statement balance, cutoff, ledger evidence and signed
difference (statement minus ledger). Missing FX is not a native discrepancy.
Differences may exceed one Money range and remain exact strings; a single posted
adjustment is Money-bounded. Reports are immutable and subsequent comparisons
are distinct revisions. No automatic income/expense classification occurs.

An adjustment requires explicit equity offset choice and reason, fresh matching
financial evidence, and an active owned account. It posts on the statement's UTC
business date, then saves a linked new comparison in the same transaction. One
comparison gets at most one adjustment. Retries return the durable result. The
ordinary attributed reversal path can reverse a reconciliation adjustment; its
effective date remains explicit in the resulting ledger history.

No automated feed, bank access, statutory FX accounting, speculative trading,
M2 feature, main/stage promotion or deployment is in scope.
