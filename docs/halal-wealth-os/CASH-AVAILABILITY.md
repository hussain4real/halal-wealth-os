# ADR-007: actual cash availability and isolated forecasts

Phase policy under the authorized M2-P3 scope; engineering review and human
phase acceptance remain separate. This decision adds a narrow cash panel and
what-if calculator, not the later reporting dashboard or an investment adviser.

## Actual now and protection

Use every owned non-system bank/cash account, including archived accounts.
Investments, liabilities and clearing accounts are excluded. Preserve signed
cash, including overdrafts. Read posted/reversed journal movements effective on
or before the current UTC business date. Pending/outside-cutoff openings,
legacy correction timing and out-of-range balances make the result incomplete.
No future expected salary, goal target, contribution plan or funding-link total
is current cash.

An open dedicated goal protects the account's positive current cash once.
Its historical high-water is explanatory history, never another deduction.
An open reserved goal protects actual account-wide FIFO backing, ordered by
reservation ID, including earlier claims beyond visible pages. Released and
closed claims protect zero. Nominal reserved claims and their backing shortfall
are disclosed separately; underfunding is not subtracted again from cash.

For each native currency:

    unearmarked = signed liquid cash - actually protected cash
    unfunded required = unpaid required commitments - effective explicit overlap
    raw native = unearmarked - unfunded required

Convert these coherent native groups using the policy below, then subtract the
explicit base-currency minimum buffer. Show signed raw availability and its
shortfall; deployable-now is max(0, raw), only when all required inputs are known.
An incomplete result never displays a partial subtotal as deployable money.

## Explicit planning settings and overlap

Settings are immutable attributed versions, saved with a scoped request identity
and expected prior version. Currency, nonnegative buffer (including explicit
zero), next expected income date and investment target are explicit. The income
date is an owner-confirmed planning assumption, not a bank instruction or a
guarantee. A missing/past date is incomplete and is never silently advanced.
Required obligations include all overdue unpaid occurrences and dates through
the income date, inclusive: same-day bills are conservatively due before income.

Every owned expense schedule family must be explicitly classified required or
not required. Confirmed classifications can be saved in batches while unclassified
families keep availability incomplete. A settings version supports at most 1,000
classifications, overlap links and investment memberships in each list. Larger
workspaces remain explicitly incomplete rather than discarding earlier entries. A new unclassified family makes availability incomplete. Versions
inherit the family's designation; recurrence/cutover/end dates retain M2-P1
rules. Generate full occurrences read-only, including not-yet-materialized rows;
do not write on GET. Once, term and annual bills count when due, without adding
their normalized monthly provision again. Effective paid allocations are
unreleased matches to posted journals effective by the current UTC date. They
reduce only the expected unpaid residual; their expense already lowered cash.

An overlap explicitly identifies a reservation row, schedule version, due date
and native amount. Names and category flags never infer a link. The reservation
and obligation must share owner and currency. A policy cannot associate more
than a reservation's nominal grant or an occurrence's expected amount. On read,
allocate only still-backed capacity, capped by the unpaid residual. Stable
reservation/schedule/date order prevents reuse across links. Released or
superseded links give no overlap; their protection/history remains visible.
Paid matches, withdrawals, reversals and release therefore recalculate overlap
without altering its retained configuration. Dedicated protection has no
reservation-row identity and cannot be used as an inferred obligation overlap.

## Monthly forecast and scenarios

Forecast period is the next Qatar calendar month, explicitly labelled. Baseline
salary is the unpaid expected salary-marked income due in that month; baseline
commitments are unpaid required bills due in that month. Both subtract effective
early-paid allocations, since those actuals already affect starting cash. Both use native amounts and
the same known current FX cutoff. This is an assumption-based forecast, not a
future valuation and not actual cash. Missing salary plans or variable goal
contributions make the affected forecast incomplete until an explicit scenario
amount is supplied, including zero.

The additional contribution assumption is the sum of fixed monthly contributions
for open goals outside the explicitly selected investment-goal set. It is a new
earmark, not a household expense or an automatic transfer. The investment target
is a desired total protected amount, not an additional deduction on top of its
selected existing investment reservations. Its additional earmark is
max(0, target - actually backed selected investment goals). Selected investment
goals are explicit reserved-goal identities; no name-based purpose inference.
An investment-target reservation cannot simultaneously be linked to a required
bill in the same settings version.

    forecast household cash = actual liquid cash + salary - commitments
    forecast remaining protection = actual protection - monthly bill overlap
    forecast free funds = forecast household cash - forecast remaining protection
                          - additional contribution
                          - additional investment earmark - buffer

Monthly bill overlap uses the same explicit, still-backed links as actual now,
limited to next-month bills. Paying those bills consumes their reserved cash,
so their protection is removed from the forecast remaining protection rather
than subtracted twice. Cap converted overlap by both the scenario commitment
amount and the currently converted actual protection. Separately rounded tiny
foreign flows can otherwise release more converted protection than exists.
Disclose any overlap held back by this rounding cap; never present negative
remaining protection or create extra forecast free funds from that difference.

Salary, contribution, investment target and commitment scenario amounts replace
only those forecast assumptions. They never change actual-now inputs or output.
The scenario endpoint is a pure calculation: no persistence, journal, schedule,
goal service, reservation, bank action or notification. Reset/navigation/retry
only discard or recalculate the scenario. A baseline fingerprint accompanies
the request; a changed source snapshot returns a stale-state conflict rather
than presenting the old assumption as a calculation of current records.

## Exact FX, coherent reads and bounded disclosure

Within the owner advisory boundary shared by all app financial/planning writers,
read cash, protection, settings, obligations and owner reporting quotes. Use the
latest direct native-major/base-major reporting observation ordered by effective
time, observed time and ID; both times must be at/before the capture instant.
More than seven days by either time is stale; exactly seven is valid. No parity,
reciprocal, triangulated or future-rate assumption. Same currency is identity.

Sum native components per currency before conversion. Convert cash,
unearmarked cash and raw-native residual at that same granularity with existing
HALF_UP cash rounding. Display converted protection as cash minus unearmarked,
and converted unfunded commitments as unearmarked minus raw-native. These paired
differences preserve the exact displayed arithmetic and prevent rounding from
creating deployable cash when a foreign account is fully protected. Retain
native components and attributed rate identities alongside the result. Missing,
stale or overflowing relevant components make the consolidated result incomplete.

Use full SQL aggregates/windows for financial totals and at most twenty visible
detail rows per section, with owner-scoped keyset choices and retained selected
identities for recovery; disclosure limits never truncate calculation inputs.
A cheap recurrence-size check refuses evaluation above 100,000 candidate
occurrences; actual generation uses the same per-version end/cutover bound,
with an explicit incomplete state and unknown occurrence count.
It does not calculate a truncated surplus or erase old obligations.
All money and rates are exact strings, with bounded Money validation before
presenting totals. Settings and links retain immutable owner-scoped evidence.
Read-only scenarios are stateless; only settings writes require durable retry.

## Verification mapping

AC1: private original F07, separately reviewed anonymous surplus and alpha flow.
AC2: explicit funded/unfunded overlap, paid/reversed actuals, target membership,
FIFO withdrawal/release and future-salary separation. AC3: signed overdrafts,
negative raw, protected underfunding and exact range/rounding boundaries.
AC4: authoritative-table byte comparisons across every scenario field, reset,
repeat and stale request. AC5: missing settings/designations/FX, quote cutoff,
direction/staleness, unresolved opening and incomplete native evidence.
Independent source/rendered reviews and full uncached/TIA/publication gates
remain separate observed checkpoints, never inferred from this decision.
