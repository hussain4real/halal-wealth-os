# Dated manual valuation and performance

M3-P3 implementation choices; all observed gates are recorded separately in
[runs/M3-P3-local.md](runs/M3-P3-local.md). This contract is not phase acceptance.

## Evidence, time and units

Manual unit prices are immutable owner/account/asset-family observations, bound
to a retained asset-reference version and currency. Corrections append a series
version. Both observed and effective UTC instants must be at or before cutoff;
selection is latest effective instant, observed instant, then ID. Apply cutoff
eligibility before supersession: a later correction cannot erase an earlier
historically eligible version. Seven days by either timestamp is the explicit
manual-price staleness boundary; exactly seven days is eligible. Stale prices
and absent prices have distinct reasons; neither becomes zero or falls back to
execution price, basis or an IPO application. An explicitly observed zero is valid.

Prices use raw units after every active retained split through their effective
UTC business date. Entry requires explicit confirmation of that unit basis,
bound to server-issued retained owner/account event, opening and mode context.
Changed history rejects a new submission and requires reloading/reconfirmation;
a committed same-request receipt recovers before this staleness check.
Changing the asset, effective time or price clears the UI confirmation,
including same-date splits. The retained split identity hash must equal the
valuation's split context; later or retrospectively corrected splits invalidate
incompatible prices. There is no automatic split adjustment. A later observation
in compatible units is needed. Account-specific context avoids assuming that
separately retained account corporate-action evidence is identical.

An opening reference or a manually valued non-unitized position retains its
existing same-UTC-statement-date policy. No invented units or future value is
introduced. New unit-price observations feed the common Portfolio contribution
and NetWorthSnapshot path; cash-only ledger reconciliation remains unchanged.
Aggregate mode still selects its one whole-account value, excluding cash,
positions and paid IPO receivables. A price cannot clear a stale aggregate event
watermark. Historical captures reconstruct retained business-date evidence;
they do not claim visibility at a past database commit time.

## Native and translated costs

Native quantities, weighted-average cost, realized gains, unknown-basis rules
and cancellation ordering remain InvestmentReplay's accounting truth. A separate
translated pool converts each acquisition's consideration and capitalized fees
using direct transaction-purpose FX at the acquisition UTC business-date end,
capped at the report cutoff on its final date.
This date-end convention reflects date-only investment facts; it is not an
invented execution instant. Both FX timestamps must be eligible, with the existing
inclusive seven-day policy, no reciprocal inference or triangulation. Reporting-
purpose rates are used for value at the actual valuation cutoff, never as a
replacement for missing transaction evidence. Every selected FX ID/version and
its purpose/time/source remains in the saved calculation evidence.

A known opening basis may supply a sourced acquisition date at or before its
statement date. A mixed-date pool or absent acquisition evidence must leave that
date blank; foreign translated cost stays unavailable. Same-currency and known
zero cost need no inferred historical rate/date. Unknown native basis remains
unknown independently of a known market value. A complete disposal consumes the
translated pool's final residue and a subsequent known purchase starts anew.

Each IPO payment adds its own payment-date translated receivable basis. Confirmed
allotment and refund allocate the remaining pool by the exact native receivable
ratio, consuming the final residue on exhaustion. Allotment never substitutes
its later FX for payment evidence. Refund cash uses refund-date transaction FX;
the difference from the released payment basis is separately labelled receivable
refund FX gain/loss. Missing payment or refund FX makes that field unavailable.
These results never post extra cash or become unconfirmed shares.

Money uses signed exact minor-unit strings; units/prices retain twelve decimal
places. Quantity times price keeps twenty-four places before half-up native cash
settlement. FX then converts that settled native component and rounds half-up at
base settlement. Selected component settlements are summed in both new investment reports and
the shared detailed-account net-worth path. Historical saved reports retain
their earlier account-settlement policy; they are never rewritten. All totals are exact before four-decimal percentage display;
valid large intermediates are never narrowed through floating point. Overflow
leaves the affected monetary field unavailable, preserving other known fields.

## Allocation and supported periods

Select detailed cash, holdings and paid receivables once, or one aggregate account
value once. Allocations group selected components by retained asset reference,
native currency, explicit sourced geography and account. Unclassified geography,
cash and aggregate accounts without look-through remain unknown. Classification
belongs to the retained asset version; applying a newer classification to an
existing opening requires its explicit reference revision through opening history.
Never infer geography from currency, market or platform.

The denominator is the sum of known converted selected component values. State
partial coverage and component counts; a covered-subset percentage does not claim
full-portfolio coverage. Signed cash and liabilities remain signed. Nonpositive
denominators suppress percentages. Exact group numerators reconcile to the exact
denominator; four-place display percentages can have a rounding residual. Missing
components retain null values and independent reasons, with known subtotals.

Performance compares closing UTC business-date states using price/FX eligibility
at each exact supplied cutoff. Flow interval is (start UTC date,end UTC date];
same-date period endpoints are unsupported. All scope members, confirmed mode
identity and opening identity must agree. Only complete endpoints and a positive
starting value allow base simple return. Native simple return can remain available
with complete native endpoints in one currency even when reporting FX is missing.

Immutable investment-journal members are internal transformations, including buys,
sells, dividends, fees, IPO payment/allotment/refund and reservation lifecycle.
Net income and fees already enter ending cash and are not added again. A native
transfer is internal only when both personal legs are inside the selected scope
and sum exactly to zero. Other generic movements and FX operation groups remain
external/unsupported; their retained journal IDs and native signed legs are shown.
Unsupported flows or scope/mode transitions suppress return percentages. Monetary
change remains separate from contributions and never masquerades as total return.
TWR/XIRR and other cash-flow-adjusted metrics are explicitly deferred.

For a supported homogeneous native scope, native monetary movement translated at
the starting reporting FX is shown separately. The residual base change is the
FX and settlement effect, including row rounding. Do not linearly add native and
FX percentages. Realized gains, net income, standalone fees and refund FX results
use the selected event-date range, or an explicitly labelled lifetime range when
no period is selected. An unknown gain outside a selected period does not turn a
later known period gain into a fabricated lifetime total.

## Coherence, reuse and saved identity

Single-account scope rejects owned internal/control accounts rather than returning
a misleading complete zero. Archived supported financial accounts remain readable.
A fresh capture uses PortfolioRead's repeatable-read transaction. Input fingerprint
and cached result selection occur inside that same snapshot. Cache identity covers
owner, exact account/all-account scope, base currency, cutoff, period start,
calculation policy and all retained owner financial/version inputs plus account
metadata and opening-posting links. This conservatively invalidates on unrelated
owner changes as well. Cache values expire after ten minutes; they are never
financial truth. There are no provider calls in valuation or cache paths.

Saved investment valuation runs use a dedicated immutable table and request kind,
separate from legacy net-worth/ledger reports. The common owner lock and ordered
account locks serialize capture with financial writes. The same request identity
and consequential payload recover the original saved receipt after source changes;
changed payload conflicts. A separate refresh creates another immutable run.
Migration downgrade refuses before DDL when valuation/classification/acquisition
facts would be lost. No mutation of earlier financial history is needed to adopt
this schema.
