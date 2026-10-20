# Purification recording policy

This workflow records attributed instructions and actual payments already made.
It does not issue rulings, calculate zakat, transfer money or liquidate assets.
No rate is supplied by default. Missing source, assessment, method, applicable
base or rate/fixed amount leaves the amount pending, distinct from sourced zero.

The following are explicit implementation choices consistent with the global
contract; the canonical phase did not prescribe these narrower policies.

- Amounts settle in the distribution's native currency using HALF_UP once.
  Percentage arithmetic retains all twelve rate decimal places through the
  full integer numerator; division by 100 is not rounded early.
- Gross and net bases derive from the exact recorded distribution. A separately
  stated custom income base retains its source explanation. Market values are
  never available as a calculation base. Fixed instructions retain an explicit
  applicable base and source as well as their fixed settlement amount.
- A stable method reference identifies one obligation series for a distribution
  lineage. Method-version and basis/rate corrections append versions; a new
  request key cannot silently clone the same series. Distinct sourced methods
  remain visibly separate, without an invented religious priority or cap.
- Payment recording composes the existing manual expense posting transaction.
  Linking a journal already recorded never posts it again. A unique payment
  reference retains the user's explicit charity attribution; an ordinary
  expense is not automatically classified as charity.
- Allocations use the same native currency and cannot exceed the actual payment
  or known current outstanding amount. No cross-currency allocation is supported.
  Capacity counts all retained active allocations, independently of UI paging.
- A downward revision retains paid history, shows any excess separately and
  clamps outstanding to zero. It does not refund cash or silently free payment
  capacity. An explicit full allocation release appends an audit record without
  moving cash. Missing instructions retain linked amounts but remain pending.
- Reversed payment journals remain in history and cease contributing to settled
  amounts; the obligation reopens. A replacement expense requires explicit new
  charity attribution/linkage. Reversed or replaced distribution evidence is
  flagged for review; new allocations require a version tied to its current
  distribution evidence. No correction silently creates a religious result.

Shariah's purification application service coordinates Portfolio reads and the
Ledger posting port in one transaction. Pure calculation has no database or
Ledger dependency. Ledger does not import Shariah policy. All reads and writes
retain owner isolation, including administrators, and string financial values.
