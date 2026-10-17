# Global invariant index

These are inherited requirements for future applicable phases, not claims of passing tests.

| ID     | Requirement                                                                              |
| ------ | ---------------------------------------------------------------------------------------- |
| INV-01 | Journal postings balance per currency and commit atomically                              |
| INV-02 | Money, units, price and FX remain exact, bounded and currency-explicit                   |
| INV-03 | Corrections preserve attributed history through reversals/versions                       |
| INV-04 | Transfers, goal funding and reservations never duplicate wealth or activity              |
| INV-05 | Cash cannot be reserved twice, including under concurrency                               |
| INV-06 | Holdings derive from events; IPO applications are not shares                             |
| INV-07 | Aggregate and detailed valuations never both inflate totals                              |
| INV-08 | Price/FX observations retain provenance and time; missing/stale/future data are explicit |
| INV-09 | Shariah evidence preserves uncertainty, conflict and history                             |
| INV-10 | Ownership isolation applies across every data access path                                |
| INV-11 | Implemented retries are idempotent; residual delivery risks are documented               |
| INV-12 | Tests, approvals, live checks and external actions require observed evidence             |
