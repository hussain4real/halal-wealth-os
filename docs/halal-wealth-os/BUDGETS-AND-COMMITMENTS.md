# Budgets and recurring commitments contract

M2-P1 bounded implementation decisions. Passing evidence belongs in the phase
run; these decisions do not accept a phase or milestone.

Forecasts and ledger cash are separate. Planning never calls a posting service
or updates journal metadata. A posted receipt affects cash before it is matched;
matching only adds explicit Planning-owned annotations and allocations. The
informational standing-order flag creates no bank instruction or reminder.

## Calendar and version identities

Planning dates are Asia/Qatar calendar dates. The owner supplies the first due
date; its day configures the monthly salary/due day. Monthly, configured term
(2–24 months), annual (12 months) and once-only rules have explicit cadence.
Missing month days clamp to the last day without shifting the original anchor
or assuming bank/weekend rules. Optional end dates are inclusive.

Each immutable schedule revision has a NEW schedule row ID. An occurrence is
identified by that version ID and due date, never a mutable logical schedule ID.
Monthly GET projections are read-only; matching materializes the exact occurrence
snapshot once. A revision uses an explicit Qatar cutover on/after today, a new
first date on/after cutover and a reason. Older versions apply before cutover;
retained old future occurrences are superseded. A cutover that would replace an
effectively allocated future occurrence is refused until explicit release.
Earlier overdue items remain discoverable by selecting their month.

Categories retain an immutable family identity and direction across versions;
name and the independent normal-budget/emergency-essential flags are versioned.
Category revisions do not automatically retag schedules, occurrences or actuals.
Occurrences retain their exact category version. Actual reclassification is an
explicit attributed version with a reason; current historical views use the
latest explicit classification, not an implied immutable saved budget.

Term/annual monthly provision is expected minor units divided by configured
months, rounded UP to a minor unit. It is a separate conservative planning input,
not another due outflow, reservation, paid expense or funded sinking balance.
Term/annual rules require an explicit provision start on/before the first bill;
it may precede that first due month. The cadence-normalized provision does not
claim enough has been saved by the first bill. Monthly essentials use expense
rules active at month end (including that provision start), independently of
normal-budget inclusion. Once-only commitments remain dated due obligations,
not an invented recurring essential denominator. No coverage-month or deployable
cash calculation from later phases is implemented.

## Actual activity and matching

Actual income/expense comes from its system-account counter-leg, including
attributed original and reversal signs on their own UTC ledger business dates.
Transfers, openings, FX principal and reconciliation equity are excluded; actual
FX fees are expense once. A replacement requires explicit classification.
Unclassified activity is visible. Qatar due months and UTC date-only activity
months share a month label without fabricating a precise transaction instant.

Retained earlier-policy linked corrections can cancel in another month. The
original and cancellation month components then become explicitly unavailable
for reliable period comparison; journals are not rewritten. A month containing
both dates nets them normally; a month containing neither has no original/
cancellation activity. This monthly boundary differs from daily balance cutoffs.

One occurrence may receive multiple actual allocations; an actual may split
across occurrences. Positive exact allocations require the same currency,
direction and stable category family. Effective allocations cannot exceed either
the expected occurrence or the posted actual's economic amount. One unreleased
effective pair is permitted. No overpayment, inferred completion or automatic
matching occurs. All monetary values remain exact strings in native buckets;
aggregate overflow is unavailable rather than truncated or treated as zero.

Allocations/releases and classification revisions are append-only. Reversing an
actual makes its retained allocation ineffective and reopens the occurrence with
a review warning. A replacement may then be explicitly classified/matched.
Releasing or rematching never changes ledger history. Changing classification to
another family while effective allocations exist is refused; an explicit newer
version in the same family preserves allocation eligibility and old snapshots.

Owner authorization precedes durable retry lookup. Mutations and combined reads
use the existing owner advisory transaction lock shared with posting, with no
reverse Ledger dependency. Exact request fingerprints, owned composite foreign
keys, immutable-row guards and deferred allocation capacity checks protect the
boundary. They do not imply an external exactly-once delivery guarantee. Retained
planning history prevents destructive downgrade/profile deletion; empty-schema
rollback/remigration remains supported.

Public regressions use anonymous translations; original F06 is verified privately.
No private salary, support amount, bank details or source-original profile is
adopted. No goals/reservations, deployable cash, live integrations, default
reminders, later phase, main/stage promotion or deployment belongs to this phase.

## Bounded history reads

Monthly posted totals and comparisons include all economic activity in the chosen
UTC ledger month. The visible activity list uses 20-row keyset pages; changing a
page never changes totals. Matching loads eligible receipts only when requested,
with 20-row pages and literal description/reference search over every posted date.
Arbitrarily old prepayments and late receipts remain eligible for explicit matching.

Due cards show up to ten recent allocation links. Full retained matching history
is available in pages of twenty, including released and reversed links. Remaining
amounts, status and review warnings always use every allocation. A remembered
selection can still be displayed after becoming full or reversed so a lost-response
retry can recover its original saved identity; it does not grant a new allocation.
Paginated responses are fresh coherent reads, not one immutable cross-page snapshot.

An attempted match retains its exact receipt/amount in tab storage under the
owner, occurrence and current pending request identity. Hard reload can restore
that pending request even if its occurrence became fully matched. Confirmed
success removes the draft and advances the request identity; stale drafts cannot
become a new request. Denied tab storage leaves current-session recovery and
retained history available, without claiming draft persistence after reload.
