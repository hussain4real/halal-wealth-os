# Phase run record template

Use one record for the authorized phase/run. Keep private identity, local paths, authorization transcripts, account data and full logs in owner-controlled evidence outside this repository. Public summaries must pass a separate publication review.

## Authorization and content identity

- Phase, execution-plan version and documentation version:
- Human authorization reference and permitted actions:
- Worker/run identity and exclusive ownership evidence:
- Verified remote, repository/worktree/base and HEAD; private path evidence reference:
- Exact baseline and final dirty/untracked lists; private inventory reference:
- Uncommitted content identity, hashes or diff artifact:
- Accepted dependencies and presence in this checkout:
- Runtime, database, coverage and lockfile evidence:

## Bounded checkpoints

| Cycle | Criterion/check                 | Documentation or implementation change | Exact command and exit | Finding | Next action |
| ----- | ------------------------------- | -------------------------------------- | ---------------------- | ------- | ----------- |
| 1     | Record the authorized criterion | No activity implied by this template   | NOT_RUN                | Pending | Pending     |

At most six cycles per authorization; stop earlier after three materially unsuccessful attempts on the same issue or any runbook blocker. Reference private logs without disclosing their contents.

## Requirement mapping

| Requirement             | Test/manual check       | Command/artifact reference | Observed result                    |
| ----------------------- | ----------------------- | -------------------------- | ---------------------------------- |
| Phase acceptance ID     | Specify an actual check | Exact command/log/hash     | NOT_RUN                            |
| Applicable invariant ID | Explain applicability   | Exact evidence             | NOT_RUN or explicit NOT_APPLICABLE |

## Outcome and verification

- Worker outcome: READY_FOR_REVIEW, BLOCKED, PAUSED or HANDED_OFF; never self-accepted.
- Changed files and purpose:
- Domain/integration tests and counts:
- Coverage paths, driver and measured coverage:
- Type, lint, build and migration evidence:
- Browser/manual evidence:
- Baseline failures versus introduced failures:
- Live external checks versus mocked checks:
- Remaining blockers, smallest next step and owner decision:
- Authorized remote evidence, or NOT_ATTEMPTED:

## Handoff, if authorized

Record explicit authorization, previous owner stop acknowledgment, current clean/dirty state, HEAD, preserved uncommitted files/worktree, authorized remote evidence and receiving owner. Handoff ends the old owner's authority; resumption requires explicit authorization.
