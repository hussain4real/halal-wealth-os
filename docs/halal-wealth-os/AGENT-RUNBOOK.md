# Documentation-stage runbook

Status: public-safe local derivative reconciled against the verified user-supplied October kit. Original files and private evidence remain outside this public checkout.

1. Read current human authorization, applicable AGENTS.md, this runbook, architecture, invariants, validation, state, selected phase and accepted dependency evidence.
2. Verify repository/worktree/base/HEAD, dirty paths, owner and runtime before edits. Preserve unrelated work; do not reset or stash it.
3. One phase has one implementation writer. Do not spawn implementation subagents. An independent reviewer stays source-read-only.
4. The manager alone changes active-run claims and central acceptance. A file is not a distributed lock. Initial docs-only M0-P1 may run with no active claim; leave active_run empty. Before M0-P2, explicitly record the authorized owner/worktree and claim. The kit's plan_version=1.0 is preserved; documentation_version=1.1 records the document separation without resetting history.
5. Verify dependencies in the actual checkout. Crossing milestones additionally requires the preceding accepted independent review.
6. Work within the phase and authorization. Default phase authority does not include commits, pushes, PRs, merges, provisioning, credentials, public sharing or deployment. The empty public repository creation and the final reviewed planning-package publication were separately authorized. This narrow exception does not authorize M0-P2, general remote writes or deployment.
7. Map acceptance IDs and applicable invariants to observed checks. Keep private evidence outside this checkout. Preserve command/exit/status/content identity; never invent a passing result.
8. Keep a bounded loop: at most six scoped implementation/verification cycles per authorization; stop earlier after three materially unsuccessful attempts at one failure or any ownership, runtime, permission, dependency or scope blocker. Never weaken tests or reset budgets.
9. Worker outcome is READY_FOR_REVIEW; owner acceptance is separate. Missing mandatory evidence remains a blocker even in a review package. Never mark another phase accepted or begin M0-P2.
10. On explicit handoff, stop edits and remote writes, preserve the checkout, and report exact state. The receiving owner re-verifies ownership and evidence before resuming.

## Additional kit controls

Never widen permissions, change credentials, install OS services, rotate keys or alter dependency limits to bypass a blocker. Preserve baseline failures; do not weaken assertions, skip required tests, inflate coverage exclusions, substitute SQLite for PostgreSQL integration evidence or report a missing harness as passing.

Pause earlier than the cycle limit for changed ownership, scope conflict, missing required runtime/secret, unapproved dependencies or the configured client budget. Record exact commands, exits, findings and next action at every checkpoint. A human may authorize a new budget; the worker cannot reset its own limit. No invented background resumption is allowed.

Before resuming, re-read instructions, state, run evidence, HEAD/diff and accepted dependency evidence. Re-run affected checks after content changes. Milestone review is separate from phase review: all constituent phases must be accepted, an independent reviewer reports READY_FOR_OWNER or CHANGES_REQUIRED, and only the human accepts the milestone gate. Deployment always needs separate authority.

Use the [run evidence template](runs/TEMPLATE.md). Full logs, exact local paths, authorization transcripts, source originals and uncommitted content hashes remain in private owner evidence. A public-safe run summary may refer to them without publishing their content.

The original state was unclaimed with all phases PLANNED. Prior local drafts were preserved privately before schema alignment. The subsequent human decision accepts M0-P1 and the six ADR directions only; active_run remains null, milestone gates and later phases remain unaccepted. Native Goal activation remains unverified because app inspection was denied; it was not retried. The CLI feature flag is not proof of an active goal.
