# M1-P3 dev publication and merge

Status: MERGED_POST_MERGE_CI_PASS, phase and milestone unaccepted. This local
record follows the authorized normal dev merge; it is not additional remote
publication or human acceptance. Three of at most six implementation/review
cycles were used; earlier histories remain retained.

[PR #5](https://github.com/hussain4real/halal-wealth-os/pull/5) was first opened
as a draft and normally merged after fresh exact-head gates.

- Accepted dev base:8a4b5719ffab5aafb8916fe0586e9a20e7e08917.
- Reviewed corrected head:b3e456544f0ccebea782f3f8d95f9c3d4309aa2d.
- Reviewed tree:cd7141a6969fafecd6f29bc1caa10ffd7bc76a7e.
- Normal merge:b0f54bfa60dc2cb866ad35b7f52447a1578f600d.

Both merge parents and the tree were independently checked. The merge contains
the reviewed candidate unchanged. Main/stage remain at
9f8db16620aabec70691538d82066eddc2d01e49. No deployment or M2 work occurred.

## Original CI observations

Every listed run is its original attempt1; no rerun replaced a failure.

| Checkpoint                                               | Original run                                                                            | Actual baseline/restoration | Covered statements |
| -------------------------------------------------------- | --------------------------------------------------------------------------------------- | --------------------------- | ------------------ |
| Initial PR head a25b80c3b3157fcfbd2dd226bd36af89bdc96d90 | [39103689107](https://github.com/hussain4real/halal-wealth-os/actions/runs/39103689107) | 300 tests/1,830 assertions  | 1,047/1,132        |
| Corrected reviewed head                                  | [39105496328](https://github.com/hussain4real/halal-wealth-os/actions/runs/39105496328) | 310 tests/1,927 assertions  | 1,053/1,137        |
| Dev merge push                                           | [39105779693](https://github.com/hussain4real/halal-wealth-os/actions/runs/39105779693) | 310 tests/1,927 assertions  | 1,053/1,137        |

Raw logs confirm successful baseline, the intended additional failing Pest case
causing verification exit1, and restored successful baseline. The corrected
full gate retained every prior accepted test, locks, PHP8.5-only, starter
features and uncached coverage. Platform/advisory, isolated migration smoke,
frontend lint/types/build, Pint, PHPStan and16 Node checks passed.

## Review and correction

Hosted review of the initial head found one P1: past-date FX corrections could
double cash at intermediate cutoffs. Six ordinary/FX regressions reproduced it
before correction and passed afterward. Cancellation now uses the original
business date for every principal and fee; replacement keeps its own date.
Standalone reversals retain today. Retained legacy conflicts become explicitly
unavailable only in their ambiguous window, without rewriting immutable rows.
Deferred guards reject both mismatched member dates and uniformly wrong new
correction dates. The corrected source passed exact-head automated review with
no findings; the original thread was answered and resolved.

Independent financial review covered28 primary and five policy/copy paths.
Separate sanitation review verified all469 staged and committed files and the
PR description against the frozen candidate, including the public noreply
identity. Fresh pre-merge inspection covered all reviews/comments/threads,
checks, exact head/base, mergeability and main/stage identities.

Actual rendered review passed36 distinct broad checks/34 inspected images,
then16 scoped correction/legacy checks/13 inspected images. These separate
reviews are not represented as a combined unique count. Four observed UX issues
were corrected and rechecked. Private canonical F04 passed its five checks.
Fresh local TIA recorded310 tests/1,927 assertions in124.82 seconds; unchanged
replay explicitly reused310 results in5.43 seconds, not fresh execution.

## Acceptance boundary

M1-P2 is owner accepted; M1-P3 remains READY_FOR_REVIEW and unaccepted. The
authorized [independent M1 review](M1-independent-review.md) is recorded
separately. The [runbook](../AGENT-RUNBOOK.md) requires all constituent phases
to be accepted before formal milestone clearance. Neither this merge nor any
automated result constitutes that human decision. No M2, main/stage promotion,
live financial connection, new credential or security-setting change is claimed.

## Subsequent owner decision

The owner explicitly accepted M1-P3 and M1 at07:19UTC on18 October2026 and
approved publishing these final records plus M2-P1 work. Earlier unaccepted/
CHANGES_REQUIRED statements describe their observed checkpoint. See
[M1-P3 acceptance](M1-P3-acceptance.md) and [M1 acceptance](M1-acceptance.md).
The accepted application source and original test evidence remain unchanged.
