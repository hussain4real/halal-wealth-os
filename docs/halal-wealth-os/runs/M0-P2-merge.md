# M0-P2 approved merge checkpoint

Status: authorized; merge and conditional owner acceptance pending fresh checks.
The human owner approved merging PR #1 to dev and proceeding with the canonical
next phase after post-merge CI. This is a new authorization checkpoint; prior
six-cycle publication history and earlier runtime records remain intact.

Fresh read-back matched the approved head
9ebbc61e04bf324670bd8cf6bf6c624d3689b79b, tree
fe8b9c7f619ca134242119598c5f1ded23c9972a. The PR was open, mergeable and based on
dev, all reported checks succeeded, and the independently reviewed source was
unchanged. No change-requested review was recorded. main/stage/dev remained at
the original planning baseline. Repository merge commits are allowed; the
merge will match the freshly verified head and never use a safeguard bypass.

Fresh review also identified a real mixed-case profile email defect. Three
PostgreSQL regression tests failed before correction: canonical storage/login,
case-variant duplicate validation and preserving verification for case-only
updates. Profile requests now lowercase string emails before uniqueness
validation and saving, matching the existing Fortify registration/login flow.
Existing starter test bodies and features remain intact. Direct registration
already lowercases through Fortify; no unsupported registration vulnerability
is claimed and no existing user data is rewritten.

Unknown lifecycle status spellings also passed before correction. Explicit
phase and milestone status sets now reject them, with two Node regressions.
Supported phase values are PLANNED, IN_PROGRESS, RUNNING, BLOCKED,
READY_FOR_REVIEW, PAUSED, HANDED_OFF and ACCEPTED. Milestones support PLANNED,
IN_PROGRESS, BLOCKED, READY_FOR_REVIEW, READY_FOR_OWNER and ACCEPTED. These
checks do not authenticate an owner decision; exact approval evidence stays
private and conditional acceptance has not yet been recorded as effective.

The database-disposability review comment describes an assurance limit:
configuration/name checks do not independently prove resource ownership or
that data is disposable. Independent review did not find a demonstrated P1
for the inspected task-owned isolated container and CI services. Keep that
operational restriction explicit. No database-side sentinel, credential,
authentication setting change or unspecified next-phase hardening is added.

The workflow now includes push to dev so the requested post-merge CI executes.
Fresh PR CI and independent review of this delta are required before merge.
Post-merge commit, remote heads and its original push CI identity will be
recorded from observed results. No stage/main promotion or deployment occurs.

The repository lists M0-P3 as Identity, ownership and application shell, with
six task IDs and five acceptance IDs. The canonical descriptions and
phases/M0-P3.md are absent. They have been requested from the manager; no
substantial M0-P3 implementation or invented acceptance criteria is authorized
by this missing information. M0-P3 starts only after the canonical scope is
reported for alignment and post-merge checks pass.

Observed local correction gate: ./bin/verify --all passed 55 tests and 171
assertions, including all original 52 cases; coverage includes 149/159 app
statements. Platform, lock/audit, disposable PostgreSQL migration smoke,
frontend lint/types/build, Pint and Larastan also passed. Separate TIA recording passed all 55 cases/171 assertions in 16.3 seconds
wall time; unchanged replay explicitly reported 55 replayed in 2.0 seconds.
Cached results are not fresh execution evidence. CI remains uncached.
