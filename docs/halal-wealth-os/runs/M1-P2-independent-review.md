# M1-P2 independent review

Outcome: source/contract review PASS after scoped corrections; no unresolved
findings. Phase acceptance is separate. Reviewers made no checkout edits;
the implementation coordinator remained the sole source writer.

The contract reviewer read the private canonical phase extract, accepted
primitives and all posting/schema/controller/request/test surfaces. Review
covered owner-before-cache authorization, normalized key conflicts, fresh
account locking, PostgreSQL aggregate constraints, immutable opening links,
original-plus-reversal balances, MIN splitting/out-of-range totals, outer
transaction composition and post-outer-commit events. It independently checked
actual retained test/red-green evidence without claiming to have executed it.

Review cycle 1 found an opening retry identity gap and hidden destination error
after changing movement type. It also identified year-zero input handling for
verification. The opening-key regression failed before correction; the year-zero
HTTP request produced a server/database error before correction. Both passed as
field validation after correction. A different unused opening key now fails with
history guidance; only its original bound key replays the original receipt.
The source re-review confirmed all corrections and the documented boundaries.

The independent rendered review then found validation focus while disabled,
unnamed submit controls and narrow-screen clipping. The coordinator corrected
focus after processing, separate live status/named button text and long-text
wrapping. Source re-review confirmed these changes touched only three Vue pages;
backend, schema and tests remained unchanged. The corrected full uncached gate
passed 217 tests/1,102 assertions and 653/696 statements. Final exact publication
inventory/head review and actual hosted CI are distinct subsequent gates.

The separate [visual/UX report](M1-P2-visual-review.md) records 28 actual-browser
assertions and 22 inspected screenshots, with all observed findings resolved.
No original private scenarios, runtime logs, user data, screenshots or access
credentials are included in this public report.

## Hosted snapshot-race correction

The original PR CI passed on the initially reviewed commit. Hosted review then
found one P2: a concurrent opening/reversal commit between separate balance and
opening-status reads could produce contradictory account detail/list payloads.
The coordinator reproduced all four interleavings using a distinct PostgreSQL
backend and actual commit. They failed before correction and passed afterward
(4 cases/124 assertions). Zero and out-of-range metadata cases also passed.

The source reviewer confirmed one statement now captures opening existence,
amount, owner-scoped journal identity/status and exact posted sum. Both derived
and unavailable snapshots retain the same opening metadata; the controller no
longer issues a separate relation/status read. Public JSON shape and reviewed
Vue files are unchanged. The final full gate passed223 tests/1,280 assertions,
655/699 statements; fresh TIA record and explicit223-result replay passed.
This fourth scoped cycle preserves the earlier history and leaves exact-head
follow-up CI/review and merge verification as distinct publication gates.
