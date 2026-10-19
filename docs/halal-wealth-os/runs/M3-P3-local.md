# M3-P3 implementation and verification

Status: IN_PROGRESS, unaccepted. Cycle 3 of at most six. The owner accepted
M3-P2 at 02:04 UTC on 19 October 2026 and authorized this phase through independent
source/rendered review, full uncached/TIA gates and reviewed dev publication,
normal merge and original post-merge CI. Stop for owner M3-P3 acceptance before
formal M3 review or M4. One coordinator owns implementation.

## Baseline and cycle 1

Verified dev fffc3061b75527a1fc5efa88dfaee38147061953 and exactly four preserved
M3-P2 final records with unchanged handoff hashes before edits. Their five-cycle
history remains intact. [M3-P2 acceptance](M3-P2-acceptance.md) records the new
human decision. Canonical phase/prompt and original F11 were read from retained
Library sources; private originals remain outside the public checkout.

At the initial claim, M3-P3 implementation, full uncached, TIA, rendered, hosted
review/CI and merge gates were NOT_RUN. That historical checkpoint claimed no
passing evidence. The observed final cycle results are recorded below. Main/stage and
all unrelated work remain preserved. Independent reviewers remain source-read-only.

The following table records the historical initial claim, before implementation.
The final-source observed mapping appears in the cycle 3 section below.

| Requirement | Evidence                                       | Result  |
| ----------- | ---------------------------------------------- | ------- |
| M3-P3-AC1   | Exact native/base return and private F11 probe | NOT_RUN |
| M3-P3-AC2   | Missing-price completeness                     | NOT_RUN |
| M3-P3-AC3   | Cutoff/version/save/cache isolation            | NOT_RUN |
| M3-P3-AC4   | Mode exclusion/allocation denominators         | NOT_RUN |
| M3-P3-AC5   | Unsupported period return suppression          | NOT_RUN |

No source-original fixture values, raw logs, screenshots or authorization
transcripts are published. Separate sanitation is required before publication.

## Cycle 1 observed and cycle 2 corrections

The first core run passed three and failed fifteen cases (17 assertions) because
the cache dependency read incorrectly assumed opening intents had owner_id.
Ownership now joins through accounts. The next run passed two and errored
sixteen (10 assertions) on migrate:fresh retaining a previously declared SQL
function. The migration now uses the established CREATE OR REPLACE convention.
The corrected actual core/math gate passed 18 tests / 91 assertions. Raw failed
outputs and XML remain private. Initial static analysis reported 25 typing and
redundancy errors; corrections reduced this to one numeric-string return contract.
The contract is now explicit, pending rerun. Initial frontend lint/types passed.

Independent preliminary review identified missing separately labelled IPO refund
FX gain, transaction-date FX leaking past an intraday report cutoff, account
allocation grouped by display name, and differing row/account FX settlement.
Cycle 2 addresses these with actual regressions and shared-policy reconciliation.
Full uncached, TIA, final frozen source/rendered and hosted gates remain NOT_RUN.
Initial nine sanitized documents were independently reviewed, committed as
2e156423c13cc5587da518461557d39f4513e9b9, independently matched to committed
blobs/modes/parent/public metadata, then normally pushed to the M3-P3 feature.
Dev/main/stage were unchanged; the acceptance records await the eventual dev PR.

## Cycle 2 results and cycle 3 admission correction

The first broader new suite passed 32 tests and failed two new tests because
of missing test-file imports (234 assertions); those imports are corrected.
The combined new and prior investment/portfolio scoped regression gate passed
146 tests / 1,114 assertions, zero errors/failures/skips. Original private F11
passed against actual services in a rolled-back guarded transaction. Static,
frontend lint/types/build and planning checks passed.

Independent technical review verified all 556 frozen source hashes/modes before
and after inspection. The four preliminary fixes were confirmed. It then found
that a submitted raw price could silently adopt a split committed after its form
confirmation, and that an owned internal account could produce a misleading
complete zero scope. Cycle 2's full gate completed prerequisites, dependency
audits, migration smoke, frontend/build and static analysis, then was deliberately
stopped during uncached tests for these source findings. No full pass is claimed.
The partial raw output and process termination receipt remain retained. Rendered
review had not started; its grant remained on hold.

An actual targeted test reproduced the stale split-confirmation defect with one
failure / one assertion. Cycle 3 binds new price admission to retained server
context while recovering committed same-request receipts before staleness checks.
Internal scope rejection and explicit zero/missing/stale FX metadata are part of
the same bounded correction. Fresh source/rendered/full/TIA gates remain pending.

The owned-internal-scope defect was separately reproduced with one failure /
two assertions. Both admission corrections then passed four focused cases /
15 assertions. The complete new phase suite passed 37 tests / 248 assertions.
Two additional HTTP tampering/stale-context/internal-scope cases passed with
27 assertions. These are scoped checks; the final full gate is still pending.
The stale-form context uses the application's existing encryption key; no key
or credential was created or changed. Source review will recheck the refreshed
final source. Missing/stale FX labels remain explicit, and a foreign zero
conversion is labelled zero-amount identity rather than same-currency.

## Cycle 3 completed source, full and rendered gates

The final all-implemented `./bin/verify --all` completed with exit 0: **625 tests /
4,135 assertions**, zero errors, failures or skips; **3,466 / 3,695 executable
statements covered**. Its wrapper elapsed time was 617.54 seconds. All 51 Node
checks, prerequisite/platform/dependency/advisory checks, guarded migration smoke,
frontend lint/types/build, Pint and PHPStan passed. Native full execution used
Pest `--ci --no-tia`; cached results did not replace this gate. The complete XML,
coverage and raw output were preserved before subsequent checks.

The exclusively owned disposable PostgreSQL tmpfs reached 71% during execution.
One standard SQL CHECKPOINT completed successfully, with no source, configuration
or durability-setting changes. This is disclosed operational maintenance, not
an unattended performance benchmark. After the rendered reviewer released its
resources and the coordinator database had zero journals/users, the same owned
container was restarted with identical pinned image, user, socket, network-none,
no published ports and tmpfs configuration before TIA.

Independent technical source review passed all 556 source byte hashes and Git
executable modes before/after inspection. All 144 compiled files also matched.
The final original private F11 probe passed actual services in a rolled-back
transaction; its original values remain private. Independent rendered review
passed 42 recorded checks across broad/focused runs, including repeated/reflow
checks, with 32 current screenshots individually inspected and 25 accepted unique
evidence images. Three harness failures remain disclosed; focused recovery
completed the broad run's final navigation case. See the
[rendered review](M3-P3-rendered-review.md) for the boundary and limitations.

| Requirement | Observed final-source evidence | Result |
| --- | --- | --- |
| M3-P3-AC1 | Original private F11; opposing native/base return tests; rendered dated evidence | PASS |
| M3-P3-AC2 | Missing/stale price and partial subtotal tests; actual mobile partial/unknown views | PASS |
| M3-P3-AC3 | Historical cutoff/version, cache/save/retry and future-correction tests; immutable export browser checks | PASS |
| M3-P3-AC4 | Detailed/aggregate exclusivity, signed/covered allocation and component-settlement tests; actual allocation/export | PASS |
| M3-P3-AC5 | External-flow and scope/mode transition suppression tests; actual unsupported-return view | PASS |

INV-01 through INV-08 and INV-10 through INV-12 are covered by the full accepted
regression suite plus exact-money, retained correction, price/FX provenance,
mode exclusion, owner isolation, real PostgreSQL concurrency/coherence and
committed-response recovery checks. INV-09 introduces no new Shariah assessment
in this phase; no compliance status is inferred. This requirement mapping and
phase checklist are engineering evidence, not human acceptance.

## Cycle 3 TIA evidence

Fresh `./bin/test-impact --record` completed with exit 0: 625 newly executed
tests / 4,135 assertions, 602.12 seconds wall time (Pest 597.80 seconds).
Unchanged `./bin/test-impact` completed with exit 0: 625 replayed cached results /
4,135 cached assertions and **zero newly executed tests**, 7.06 seconds wall
(Pest 4.26 seconds). The branch-bound complete graph retains all 625 passing
results. This observed replay is not another full-suite execution.

The native planner probe selected all three valuation feature suites for a
simulated changed PHP content hash and ValuationHttpTest for the Prices Vue page.
Only previous hashes were simulated in memory; no source/cache mutation or
impacted test execution is claimed. Shared Vue changes still require the
established fresh complete baseline. All 556 source and 144 compiled hashes/modes
remained exact after replay. One ordinary SQL CHECKPOINT was used during the
fresh recording at 67% tmpfs usage; configuration and durability stayed unchanged.
The measured times are not unattended performance benchmarks.

The final independent evidence addendum passed after inspecting the actual full
XML/coverage, original private F11 receipt, rendered evidence and fresh/replayed
TIA graph/planner receipts. All six source findings are corrected and no local
blocking finding remains. Complete-candidate sanitation, exact committed-tree
verification and hosted publication/merge gates remain pending at this
pre-publication snapshot. Formal M3 milestone review and M4 remain
authorization boundaries; M3-P3 is unaccepted.
