# Portfolio account modes and opening evidence

ADR-008 implements M3-P1 only. The owner accepted M2 and separately authorized
this phase. Trades, corporate actions, price feeds, performance and live broker
integration remain later scope.

An asset is an owner-private reference entry, not proof of ownership or Shariah
status. Supported kinds are equity, unitized fund and manually valued investment.
Reference names, identifiers, markets and source notes are user supplied. Terms
outside the model are labelled explicitly. Metadata revisions retain prior versions.

Each portfolio account links one owned, non-system investment ledger account.
Unconfigured legacy investment accounts retain their existing ledger contribution.
A newly linked account requires mode review; an unconfirmed mode is incomplete.
Detailed mode contributes its actual ledger cash plus its positions. Aggregate
mode contributes one selected whole-account amount, including cash; ledger cash
and underlying positions remain visible as retained/excluded evidence, never extra
contributions. Negative ledger cash is retained. Archived accounts remain in
wealth evidence, but cannot receive new portfolio writes.

Reporting composes the Ledger snapshot and Portfolio read model within one coherent
database snapshot. The Ledger domain does not import Portfolio. Reconciliation
continues comparing ledger cash to a ledger statement. Saved historical report
JSON remains unchanged. Account screens distinguish ledger cash from their
mode-selected net-worth contribution. New reports identify this calculation policy.

Opening holdings derive from immutable opening-statement events with rows, an
as-of UTC business date, source, reason and version. A correction replaces the
full opening statement at the original as-of date while retaining every old row;
it is not a buy, sale or cash posting. The current projection derives from that
statement version. One asset family occurs once per statement. Position currency
must equal the account currency; mixed-currency holdings in one account are
explicitly unsupported here and require separate native-currency accounts.

Unitized quantities are positive and unit prices are optional non-negative exact
strings, with at most 18 integer and 12 fractional digits. PostgreSQL stores
unconstrained exact NUMERIC with range/scale checks, so excess scale is rejected
without typmod rounding. Products retain all 24 fractional places before explicit
half-up cash settlement at each position boundary. Units/prices never pass
through JavaScript Number. Manually valued positions have an optional exact native
cash value and no invented units. Unknown basis has a null amount; explicitly
known zero is distinct. Unit price never derives from basis, and no gain is shown.

Opening prices/manual amounts are dated reference evidence, not current quotes.
They contribute only for a report on that same UTC business date; a missing or
older valuation makes that position incomplete. Aggregate amounts follow the same
date policy. No stale-price threshold or ongoing quote engine is invented in
this phase. Direct reporting FX retains the existing source/cutoff/seven-day rules.
Missing values/rates and range overflow never become zero or a complete total.

Every initial mode selection or later mode/aggregate revision requires a server
reconciliation preview showing current/proposed contribution, retained/excluded
sources, differences and unknowns. It expires after ten minutes and at a UTC day
change. Mode events take effect on the current UTC business date, with the latest
same-day version selected consistently with retrospective ledger-date reporting.
No backdated mode switch is allowed. Cutoffs before configuration retain legacy
ledger behavior; future opening dates never contribute.

Confirmations acquire the same owner advisory lock as ledger posting, verify the
preview source fingerprint/date/expiry/current prior version and commit one
immutable mode event plus its retry receipt. A committed same-key retry returns
the original result before stale-preview checks. A changed payload conflicts.
Raw database version guards serialize and uniquely constrain prior versions.
Preview creation writes only review metadata; canceling changes no mode, position
or journal. History is retained and destructive downgrade is refused with data.

Financial aggregates read every applicable row; disclosure/selector pages show
twenty rows at a time. One opening statement permits at most 1,000 rows with an
explicit validation error beyond that limit. Authenticated owner-only JSON export
preserves exact units, prices, unknown fields, dates and selected-mode sources.
Platform labels imply no connection, verified ownership or compliance. No broker
credentials or external financial calls are required.
