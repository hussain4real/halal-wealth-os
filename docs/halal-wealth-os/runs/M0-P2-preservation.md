# M0-P2 offline review and preservation

Outcome: **BLOCKED; source reviewed for preservation and handoff**. The existing
blocked owner claim, M0-P1 acceptance, milestone gates and original runtime retry
budget are preserved. This supplement supersedes the earlier outstanding-review
statements; it does not claim application readiness or phase acceptance.

The separately authorized offline correction replaces the planning checker's
fixed M0-P1-only acceptance snapshot with lifecycle validation. It checks human
acceptance identity/time/evidence, accepted dependencies, preceding milestone
review, phase IDs and dependency consistency. Executing phases require an active
claim; claims require an owner/worktree matching the authorized phase. These
checks validate recorded structure, not the authenticity of a human decision.
Stable synthetic regression fixtures cover current, accepted and future states;
a separate test checks live state. Composer's verification command now invokes
the executable Bash wrapper directly.

A separate source-read-only reviewer inspected the harness, guards, reports,
probe, CI, runtime, starter preservation and Vite alignment. All 293 archived
starter paths remain present; 284 are byte-identical and nine differences were
reviewed. The reviewer found and rechecked the Composer command and lifecycle
corrections. The final source verdict permits preservation/handoff with the
development runtime gap still open: the PHP-only offline container lacks a
cohesive PHP/Composer/Node/npm environment and demonstrated loopback HTTP/Vite
access for full `composer dev`.

Observed offline checks: 11 Node tests passed; frontend formatting/lint passed;
M0-P1 planning harness and whitespace checks passed. The reviewer also replayed
the entire lifecycle test suite in memory against later accepted M0-P2 and an
accepted M0 milestone with authorized/claimed M1-P1; both passed. No real state
was accepted by those tests. Application, migrations, coverage, deliberate
PHPUnit failure/restoration, browser smoke and hosted CI remain unproved.

Read-only metadata inspection identified the existing public environment CA.
Its subject and issuer are equal, validity is December 2024–December 2034 and its
SHA-256 fingerprint is
`C7:1B:4D:1E:9D:77:75:C5:38:C6:88:AB:10:CE:A9:CE:41:7D:73:12:E8:98:38:F9:D1:5F:F7:32:56:CD:7F:C3`.
Offline OpenSSL self-signature/host-bundle verification returned OK. That does
not independently authenticate provenance or prove nested Docker trust. Only
metadata was recorded; no certificate contents/private key were transferred and
no trust, proxy or network settings were changed.

The parent reports a separately republished environment and a separate read-only
preflight. That result belongs to its task; it was not reproduced here and does
not retroactively change this task's failures. No endpoint or runtime build was
retried during preservation. A private recovery bundle retains source, baseline
Git history, uncommitted diff, reviewed evidence, file hashes and restore guidance.
It excludes installed dependencies, caches, database data, secrets and credential
stores. Transfer and ownership acceptance must be verified by the receiving
manager; filesystem visibility is not assumed. No source was committed or
published. The original workspace and services remain intact.
