# Issue tracker

Use GitHub Issues for `hussain4real/halal-wealth-os`. Run `gh` from
the repository checkout and verify `gh repo view --json nameWithOwner`
before the first write in a session.

GitHub Issues is public. Keep private planning inputs, financial and
account records, credentials, and raw evidence outside GitHub.

## Operations

- Create an issue with `gh issue create --title "<title>" --body-file <file>`.
- Read an issue with `gh issue view <number> --json number,title,body,labels,comments`.
- List issues with `gh issue list --state open`, adding `--label` as needed.
- Apply or remove labels with `gh issue edit <number> --add-label "<label>"`
  or `--remove-label "<label>"`. Use the mapping in `triage-labels.md`.
- Comment with `gh issue comment <number> --body-file <file>`. To close an
  issue, record the reason in a comment, then use `gh issue close <number>`.
- Link sub-issues with `gh issue edit <parent> --add-sub-issue <child>`.
  Record blockers with `gh issue edit <child> --add-blocked-by <blocker>`.
  If GitHub's native links are unavailable, record `Part of #<parent>` and
  `Blocked by: #<number>` in the issue body. A blocker must be closed
  before the dependent issue is ready.

**PRs as a request surface: no.**

## Wayfinding

Use one `wayfinder:map` issue with child issues for individual tickets.
Use `wayfinder:research`, `wayfinder:prototype`, `wayfinder:grilling`, or
`wayfinder:task` to identify the ticket type. Select an open, unassigned
child with no open blockers. Assign it before starting. Record the result
on the child and link it from the map's Decisions-so-far section.
