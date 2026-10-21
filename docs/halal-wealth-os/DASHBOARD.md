# Dashboard evidence and next actions

M5-P1 composes existing Ledger, Planning, Portfolio, Shariah and Decisions reads.
It needs no bank, market-data, Google or AI credentials. Dashboard operations
never post cash, trades, reservations or other financial changes.

## Totals and time

The selected valuation universe determines net worth and investments: detailed
accounts contribute their cash and positions; aggregate accounts contribute one
whole-account value. Liabilities reduce wealth once. Liquid cash covers bank and
cash accounts. Funded goals and protected cash describe backing already present
in those totals and are not additional wealth.

Native currencies remain separate. Base totals distinguish complete amounts from
covered subtotals with missing quote/rate evidence. Every metric retains source
components, account/asset identities, observation/effective times and the ledger
cutoff. Source disclosures use the captured evidence; links to current records
are labelled separately. Lists can reveal all captured contributors beyond the
initial display limit.

Current Planning readers use the Qatar planning date; financial valuations use
the stated UTC ledger business-date cutoff. A historical financial cutoff does
not imply historical reconstruction of current planning settings. The dashboard
labels both time bases and rejects a capture crossing their date boundary.

## Retained snapshots

A coherent repeatable-read transaction captures financial totals, planning facts,
settings, source records and deterministic actions. Saving retains the complete
artifact, calculation version and canonical digest. Viewing or exporting it reads
the stored artifact rather than recalculating against today's records. Captures
use owner-scoped idempotency keys; retrying a committed command returns the same
receipt. Retained evidence cannot be changed or deleted through ordinary writes
or a migration downgrade. Exports are private and never shared-cacheable.

## Rules and dismissal

Closed deterministic rules cover reconciliation, funding-plan review, attributed
evidence review and configured currency allocation drift. Each action explains
its triggering facts and threshold. A configured standing order does not produce
a duplicate setup reminder; overdue configuration is described as a plan review,
not proof that a bank transfer failed. Allocation comparison requires explicit
targets totaling exactly 100% and complete positive source coverage.

Settings are versioned. Action identities depend on the relevant triggering
state, not capture time or unrelated facts. An exact episode can be dismissed
with a reason and durable command receipt; a changed triggering episode can
resurface. Historical snapshots keep their original action output. Current
settings govern new captures, including captures initiated from a saved view.
Pending dismissal recovery is discovered by owner and operation independently
of current action visibility, including dismissed, absent and paginated episodes.
Confirmed draft clears refresh that inventory and remove recovery controls.
Ambiguous write responses preserve the original key and payload for retry;
definitive stale-setting rejection permits refresh and a new attempt.

No rule creates automatic financial decisions, external notifications or AI
advice. M5-P2 release/performance acceptance is outside this phase.
