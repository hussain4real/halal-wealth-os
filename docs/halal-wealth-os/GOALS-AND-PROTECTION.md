# Goals and emergency protection

Status: M2-P2 implementation decisions, unaccepted. These bounded policies apply
to goals and reservations; M2-P3 cash availability and scenarios remain later.
Original F05 stays private. Anonymous public fixtures preserve exact gap/ceiling,
withdrawal, concurrency and missing-input invariants without adopting private settings.

## One backing source

A goal lineage has one immutable native currency, bank/cash account and mode.
Targets, contribution plans and optional Qatar dates append attributed versions.
Close a goal to change its account/mode through a new lineage. Release all nominal
reservations first. Closed goals claim zero current cash, keeping their history.
Investment/liability/internal accounts cannot supply backing. New claims require
active accounts and known, nonnegative, in-range posted balances. An unconsumed
opening intent is unavailable. Archived accounts remain readable; no new grant.
No FX/parity is inferred and unlike currencies are not aggregated.

Dedicated mode exclusively claims the entire account for one open goal. No other
open goal of either mode may share that account. Its opening snapshot equals
current known cash at creation and is never added to its account balance. Current
funding follows all signed posted/reversed ledger movements. Its recorded account
high-water is the maximum opening snapshot plus per-journal net movements after
the coherent creation watermark. It includes retained corrections/cancellations
as historical evidence; it never supplies additional backing or reservation capacity.
A lower current balance shows the shortfall against that historical reference.

Reserved mode earmarks explicit amounts of existing cash. Opening backing is a
real initial reservation, including zero as no grant. Append allocations and full
attributed releases; release and reallocate for partial changes. All unreleased
nominal claims across goals share one account capacity. Writes serialize on the
same owner boundary as ledger posting and lock the account row to coordinate
archiving. Database guards repeat owned-source/capacity checks; requests, plans,
allocations, releases and links retain history. A later valid withdrawal is allowed.
Actual backing then follows oldest reservation ID first across all account claims:
min(claim, max(0, current positive cash minus preceding unreleased nominal claims)).
This exposes shortfalls without deleting claims or pretending cash still exists.
Cash restoration restores backing in the same order. Unknown cash stays unavailable.

## Contributions and withdrawals

Goal funding links annotate a whole existing owned account posting once per goal.
They retain its journal reference, signed amount, date, reversal lineage and note.
They neither post nor add link totals to balances/reservations. Dedicated backing
already reflects transfers once. For reserved mode, an incoming transfer supplies
account cash; an explicit capacity grant earmarks it. Actual movements, including
transfers/openings/reversals, use a separate ledger read from budget expense matching.
No notification/event delivery is needed for current backing to be correct.

Retries authorize owned sources before returning a durable result. Same keys with
changed normalized payloads conflict. A pending client attempt stores its original
exact strings and UUID in owner/operation scope until confirmed success; ambiguous
response/reload retries preserve them. Session-storage denial retains the current
app session only; hard-reload persistence is not promised in that case. Revision
recovery retains the attempted route/version. Reservation/link attempts use stable
lineage scope because configuration versions retain backing identity.

## Three separate measures

Target progress uses actually backed cash against the explicit chosen target.
Remaining deposits use ceil(positive target gap / fixed monthly contribution),
with exact integer arithmetic. A reached target needs zero further deposits even
with zero or variable policy. A positive gap with zero fixed contribution is
unreachable. Variable contributions have no fixed count or completion date.
Planned contributions never increase cash. A completion date needs an explicit
next/first deposit date; monthly addition preserves the original day anchor with
no overflow. A past configured date needs a plan update, not assumed missed
payments. Calendar overflow is unavailable while the exact count is retained.
The optional target date is a separate comparison, never an inferred first date.

Essential coverage divides actually backed cash by the selected month's positive
same-currency emergency-essential run-rate, displayed down to two decimal months.
The denominator follows M2-P1 month-end version/cutover/end-date rules, expense-only
independent essential flags, no once-only obligations, and per-schedule term/annual
normalization rounded up to a minor unit. Missing, zero or out-of-range denominators
make coverage unavailable; the explicit target remains intact. This is planned
expense coverage, not actual spend, cash, bank verification or a recommendation.

Goals, account selection, movements, reservation history and version history use
20-row keyset pages. Totals use complete history. Visible reservation goals sharing
an account reuse one balance scan and one account-wide FIFO window, with the goal
filter applied after preceding claims are counted. Dedicated high-water uses SQL
journal-net aggregates. No full-history PHP posting/allocation scan is used.

Standing orders are informational. Effective reminders stay off; opting into
delivery is unavailable in this phase and would require a separately authorized
notification capability. No bank instructions, assumed completion, automatic
transfers, interest projections or duplicate reminders are created.
