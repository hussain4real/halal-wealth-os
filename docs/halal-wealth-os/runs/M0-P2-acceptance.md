# M0-P2 human acceptance

Accepted at 2026-10-17T15:08:11.290788+00:00. The human owner approved merging PR #1 into dev and
proceeding, conditional on fresh head/check/review verification. The manager
delegated recording that decision. The conditions below were observed before
this acceptance record; this applies the owner's approval to the reviewed
starter deliverable. Raw authorization and exact private evidence stay outside
the public checkout.

- Initially approved head: 9ebbc61e04bf324670bd8cf6bf6c624d3689b79b.
- Corrected, freshly verified implementation head: 5eea7d3afc437eb1c56683b931cc068acd81aaff.
- Independently reviewed tree: 758b5e3cbf118a87cd234ceea69770b08f2eed8d, 356 files.
- [Corrected implementation CI](https://github.com/hussain4real/halal-wealth-os/actions/runs/39043431382): success, attempt 1.
- [PR #1](https://github.com/hussain4real/halal-wealth-os/pull/1): base dev.

The correction delta was independently reviewed after three profile-email
regressions failed before correction and passed after it. All original 52
starter cases remain. Full CI executed 55 tests/171 assertions, observed the
intentional 56th-case failure with exit 1, then restored full uncached success.
Coverage included 149/159 application statements. Fourteen Node checks passed.
TIA fully recorded the expanded suite and separately reported 55 replayed
results; full gates retain --ci --no-tia and real coverage.

| Acceptance | Observed evidence                                                                                                                              |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| M0-P2-AC1  | Clean locked Composer/npm installs, PostgreSQL service, frontend build and full baseline pass in hosted CI                                     |
| M0-P2-AC2  | Deliberate 56th failing Pest case propagates exit 1; restoring it restores verify --all success                                                |
| M0-P2-AC3  | Platform/coverage/tool/lock/report prerequisites fail closed; unsafe target and lifecycle negatives execute; no required skips                 |
| M0-P2-AC4  | Migration smoke executes against inspected task-owned isolated PostgreSQL locally and the job-owned disposable CI service                      |
| M0-P2-AC5  | Bootstrap/clock/validation/auth smoke, frontend lint/types/build and strict browser checks passed; future financial suites remain inapplicable |

The three published review leads are reconciled in the
[merge checkpoint](M0-P2-merge.md) and PR description. Profile canonicalization
and lifecycle typo handling are corrected with regression evidence. Database
name/configuration checks do not independently attest disposability; use is
restricted to separately inspected owned isolated resources. No sentinel or
stronger assurance is claimed, and no production/shared database is authorized.

This acceptance covers M0-P2 only. M0-P1 remains accepted; M0-P3, every later
phase and all milestone gates remain unaccepted. Only PR #1 may merge to dev
under this approval; main/stage promotion, deployment, external accounts,
persistent access credentials and protection/security changes remain excluded.
The acceptance-record delta receives fresh review/checks before merge. Actual
merge identity and its original dev push CI are recorded from observed results,
not inferred here. Canonical M0-P3 descriptions are still required before
substantial next-phase implementation.
