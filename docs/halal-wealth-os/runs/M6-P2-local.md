# M6-P2 implementation checkpoint

The owner accepted M6-P1 and authorized canonical M6-P2 on 23 October 2026 through
a sanitized PR to dev, normal merge after exact-head original CI and a fresh
review, then original post-merge CI and signed progress verification. M6-P1
acceptance and its ten-cycle history are preserved. M6-P2 and M6 are unaccepted.
One coordinator writes source; independent reviewers remain source-read-only.

A clean worktree started at dev `decef9b54d8fa67f559eb42873250a9768dbec19`.
The predecessor checkout's duplicate untracked files were inventoried and left
untouched. Canonical inputs and raw evidence remain private. The owner declined
the first provider proposal and explicitly approved Twelve Data for mock-only
implementation. See the [selection record](M6-P2-provider-selection.md) and
[access conditions](../PROVIDER-ACCESS.md). Live access remains blocked.

## Implementation and bounded history

Preparation consumed no implementation cycle. The ceiling remains six.

- Cycle 1 implemented exact provider/time boundaries and durable financial
  admission. Independent review found a skipped-midnight calendar defect and a
  late-first-response retry defect. Both reproductions and original failures are
  retained privately; neither intermediate state is reported as a final pass.
- Cycle 2 corrected late retries and added mapping/status UI, scheduled jobs,
  consumer provenance, export/recovery and focused tests. Review found a stale
  raw-unit confirmation after target changes; an unlocked identity/context
  watcher now clears that confirmation. Frozen retry payloads remain unchanged.
- The cycle 2 full uncached run executed 1,145 tests: 1,138 passed, three failed
  and four errored, with 7,673 assertions. Seven older migration/export checks
  needed successor-schema setup or section-count updates. New integration and
  source-time files met the unchanged coverage floor. This was not a passing
  full release run.
- Cycle 3 preserves the old financial assertions while correcting their setup,
  expands exact acceptance/native HTTP evidence and performs rendered recovery,
  native TIA and clean release verification. Its first fresh TIA run executed
  1,147 tests: 1,146 passed and one new
  saved-valuation test errored because it passed a portfolio ID as a ledger
  account scope. Focused IDs had happened to coincide. The original run is
  retained.
- Cycle 4 corrects that fixture with deliberately divergent identifiers before
  repeating fresh TIA and all remaining delivery gates. Its clean uncached run
  passed all 1,147 tests with 8,572 assertions, then stopped at the unchanged
  coverage floor: ValuationFx had 15 of 17 covered statements. The initial
  shared dependency-tree check also failed; an isolated install from the
  unchanged npm lockfile passed with zero advisories. Both originals remain
  retained. No passing release is inferred.
- Cycle 5 adds an observable provider-FX overflow case: a valid retained rate
  that exceeds the money range yields an explicit partial result, retains its
  source identity and creates no financial posting. It repeats affected TIA,
  independent delta review and clean full release/delivery gates. Its fresh TIA
  and clean uncached tests passed all 1,147 cases with 8,577 assertions. All 71
  concrete financial floors, native HTTP/security/performance and populated
  recovery passed. Finalization then stopped: the validator still expected the
  predecessor five-reader fingerprint set instead of the six actually restored.
- Cycle 6 corrects that strict set to require market evidence alongside all
  earlier readers. The regression failed before the change and passed afterward,
  including rejection when market evidence is absent. No receipt is relabeled;
  current full release and hosted gates must run again. This is the last
  authorized correction cycle; the total ceiling remains six.

The disabled adapters require explicit testing mode and fake HTTP. Raw daily
prices retain their calendar date and no invented publication instant; FX keeps
its supplied timestamp. Receipt cutoff and source age apply to all financial
readers. Append-only shared attempts/results count uncertain sends and bound
retries; owned mappings/admissions preserve once-only financial writes. Jobs
resume fan-out from committed results without refetching. No refresh creates a
journal, trade or downstream action.

## Observed checks and remaining gates

Focused checks passed: 24 retention/transport cases with 167 assertions, seven
mapping HTTP/job cases with 149 assertions and five real PostgreSQL contention/
retention cases with 74 assertions. Populated encrypted recovery passed with 67
tables and 21 assertions, including provider observations and failed/interrupted
requests. Later formatting and harness edits still require the final full run.

Independent cycle 2 core review passed with no outstanding material issue.
Source UI review preserves the confirmation finding and its correction; actual
rendered desktop/mobile recovery subsequently passed, including a superseded
request and an archived account. The populated dashboard/current/saved valuation
follow-up also passed; see the
[rendered review](M6-P2-rendered-review.md). Native HTTP journey, owner/admin/CSRF
boundaries, provider outage/recovery and the seeded dashboard performance gate
passed. The first standalone HTTP attempt lacked its ignored runtime directory;
that prerequisite was created and the original failure is retained.

Cycle 3 also retains an original missing test import failure, corrected before
both new valuation-reference and rolling-credit cases passed (817 assertions).
Independent harness review caught undefined tool-check names. The criteria now
use the existing HTTP journey gate, while the strict new native market evidence
checks remain mandatory. Provider selection retains its scoped hashed owner
record and exact disabled tests; no live license/tool pass is invented.

The acceptance map preserves 424 predecessor selectors and configures 78 market
selectors and five new criteria. Every selector must pass again in final uncached
JUnit; the mapping is not a passing result. The provider selection record is
mock-only, with live licensing/access explicitly blocked.

Cycle 4 fresh native TIA passed all 1,147 tests with 8,568 assertions in
171.885 seconds. Unchanged replay retained a byte-identical graph and is recorded
as cached, not new execution. Temporary PHP and market-page changes selected
seven and one affected test files respectively; both restored the exact source.
The formatter-only test import then refreshed its one affected file. The complete
current native baseline has 1,147 passing results. Shared frontend changes still
force full recording; no selective shared Vue graph or speedup is claimed.

Independent [source/sanitation review](M6-P2-independent-review.md) and the actual
rendered gates passed for their stated scope. At this source-commit preparation
checkpoint, clean full release proof and original hosted failure-propagation CI
plus a fresh exact-head review remain required before normal merge. No M6-P2 PR,
remote write or merge had been attempted at this checkpoint. Phase acceptance
and deployment remain separate owner decisions.

The prior PR21 signed post was rechecked through the existing terminal verifier:
anonymous HTTP 200, visible progress body and actual PR link passed. An earlier
plain-urllib request returned 403; both outcomes are retained. No duplicate post
was created. This does not replace M6-P2 post-merge publishing verification.
