# Proposed architecture decisions

The human owner accepted the directions of ADR-001 through ADR-006 with M0-P1. They summarize the October baseline without private examples. Implementation-specific limits and compatibility decisions listed below remain unresolved; accepting a direction does not approve runtime pins or authorize implementation. See [acceptance](ACCEPTANCE.md).

## ADR-001: exact money and precision

Use currency-explicit integer minor units for settled cash. Quantities, prices and FX use exact decimals; NUMERIC(30,12) is a proposal requiring bounded-range and rounding validation. Serialize large integers/decimals as strings; financial computation must not pass through JavaScript Number.

Use documented half-up cash rounding initially and preserve authoritative source settlement values. Configure each currency exponent; reject excessive scale/overflow. Allocate final-disposal remainders so total book cost reconciles. Tradeoff: exact arithmetic and boundary validation add work but avoid implicit binary floating-point drift. Pending: approved maximum ranges and source-specific exceptions.

## ADR-002: atomic postings and corrections

A posted journal balances independently for each currency and commits every leg or none. Transfers are not income/expense. FX operations use separately balanced legs and internal clearing; fees stay explicit. Exclude internal clearing from user net-worth totals.

Posted history is immutable. Corrections reference attributed reversals/versions. Commit related journal, investment event and idempotency records together; publish derived work after commit. Pending: database constraint strategy, concurrency/idempotency keys and failure fixtures.

## ADR-003: ownership and execution authority

Every resource, request, job, export, upload and future AI tool is server-scoped to its owner. Test two synthetic owners. Private access and restricted administration remain required even with public source code.

One authorized writer owns each phase. The manager records active-run ownership before M0-P2 and alone accepts phases/milestone gates. The M0-P1 discovery exception leaves active_run empty. Files are audit records, not distributed locks. Pending: selected runtime/worktree owner claim and authentication design.

## ADR-004: moving weighted-average book cost

Calculate book cost by portfolio account, asset and currency. Purchase fees increase book cost; sale fees reduce proceeds. Splits alter units without altering total cost. Unknown cost is unknown, never zero. Backdated changes require replay/reconciliation.

This is book accounting, not a tax-compliance guarantee. Advanced corporate actions remain deferred. Pending: deterministic final-unit rounding and replay acceptance fixtures.

## ADR-005: FX provenance and historical cutoffs

Persist currency pair/direction, rate, source, effective/observed time and immutable correction version. Valuation references the actual selected observations and calculation version. Historical runs cannot use future evidence.

Convert acquisition costs with transaction FX and current values with valuation FX. Missing/stale rates produce an incomplete result; never silently assume parity. Pending: allowed staleness, manual rate authority and provider policy. No live provider is enabled.

## ADR-006: explicit portfolio valuation modes

A detailed account contributes cash plus event-derived positions. An aggregate account contributes its selected total without adding detailed positions again. Reservations do not change wealth. IPO applications create a subscription receivable; only allotment events create shares.

Missing prices are unknown, not zero. Keep original/converted values and valuation provenance. Show simple percentage returns only where no-external-flow assumptions hold; otherwise show contributions and monetary gains. TWR/XIRR remain deferred. Pending: mode-change reconciliation and missing/stale evidence behavior.

See [invariants](INVARIANTS.md) and [validation](VALIDATION.md). The historical
ADR-001 through ADR-006 directions above do not establish implementation evidence;
accepted phase records describe their implemented boundaries. ADR-007 below
records the current M2-P3 implementation policy, with verification kept separate.

## ADR-007: actual cash availability and isolated forecasts

M2-P3 records its bounded formulas, explicit obligation overlap, conservative
income-date horizon, known-FX policy and separate scenario assumptions in
[cash availability](CASH-AVAILABILITY.md). This phase policy remains subject to
independent verification and human acceptance; no later reporting or investment
feature is authorized by this decision.
