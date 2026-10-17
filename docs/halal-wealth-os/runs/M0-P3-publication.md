# M0-P3 feature publication checkpoint

Status: prepared for authorized feature publication and original hosted CI.
M0-P3 remains READY_FOR_REVIEW and unaccepted. No merge or later phase is
approved. The manager confirmed the owner's merge-and-proceed decision and
established requirement that every PR target dev cover this delivery step.

Fresh checks found the same sole writer/branch, no intervening source changes,
and dev still at 6bac1581ade9023ecc30f27ec4c60a3575469d69. The independent final
review passed the complete local source inventory and sanitation, including
all newly added files. The application evidence is in the
[local run](M0-P3-local.md):74 tests/273 assertions,235/244 covered statements,
16 Node tests,9 strict browser checks and explicit74-result TIA replay.
Cached results remain separate from fresh uncached verification.

This uses cycle4 of the existing six-cycle budget; no history is reset.
Only publication authorization/evidence metadata changes after local review.
That delta receives independent sanitation/inventory review and affected
planning/format checks before the exact staged source is committed and pushed.
Commit authorship uses the repository owner's public noreply identity.
Existing authentication is reused; no credentials or accounts are created.

Create at most one PR for feat/m0-p3-identity-ownership targeting dev. Verify
its actual head/base after publication, preserve the first hosted CI identity,
and monitor that original run to completion. Reconcile every published review
lead using the full relevant source path and meaningful safe regression
checks; independently re-review any valid correction. Never infer CI success
from local tests or rerun a failed workflow to hide its original result.

Actual PR/head/CI identities and final review dispositions are recorded from
observed results in the PR and private evidence. No hosted success is claimed
in this pre-publication record. The prior local run's NOT_RUN statement is its
historical checkpoint. Main/stage/dev heads are preserved by publication;
only an independently approved future merge may change dev.

## Observed publication and review correction

[PR2](https://github.com/hussain4real/halal-wealth-os/pull/2) targets dev from
published head8438de75810bd74cd6d488974d5c103227415736, reviewed tree
31dd80fe86b9f846818fc2b6b8a310c588196c72.
The original [CI39046824070](https://github.com/hussain4real/halal-wealth-os/actions/runs/39046824070)
succeeded on attempt1:74 tests/273 assertions,235/244 covered statements,
intentional75th-case failure and restored full baseline. Verification reports
were uploaded. The original run is retained; it was never rerun.

Automated review completed with one
[P2 password-reset attribution lead](https://github.com/hussain4real/halal-wealth-os/pull/2#discussion_r4303337704).
Full-path inspection confirmed that the guest password.update route validates
the token through the broker, invokes ResetUserPassword and CompletePasswordReset,
and emits PasswordReset while the requester remains unauthenticated. The event's
user is its target, so inferring an authenticated actor was incorrect.

Cycle5 adds a real forgot/reset-flow regression. Before correction, the test
completed the reset as a guest but failed its exact audit-record assertion:
target ID was incorrectly actor_id, with a null subject. The listener now treats
Failed and PasswordReset as subject-only events: null actor, numeric target.
Successful login/logout attribution and sensitive-context redaction remain.
The targeted session/audit suite passed5 tests/32 assertions after correction.
Independent source review confirmed the trace and correction with no blocking
semantic finding. No credential, account, frontend, permission, trust or network
setting change is part of this correction.

Corrected local full verification passed **75 tests /281 assertions**, zero
required skips, **236/245 covered application statements**, all retained
formatting/static/frontend/platform/audit/migration gates and16 Node checks.
TIA recorded the expanded75-case baseline in35.2s wall time; unchanged native
replay explicitly reported75 replayed in2.9s. This cached result is separate
from uncached execution. The nine previously observed strict browser checks
apply to the retained unchanged UI; this correction changes audit attribution
only and its real guest HTTP path is covered by the new regression.

The complete correction/evidence delta receives exact staged inventory and
sanitation review before a normal follow-up commit/push to the same PR.
Follow-up hosted CI on that actual head remains pending at this source
checkpoint; actual identity and outcome are reported on the PR and privately.
M0-P3 remains READY_FOR_REVIEW/unaccepted, within cycle5/6. No merge or later
phase is authorized, and main/stage/dev remain preserved.
