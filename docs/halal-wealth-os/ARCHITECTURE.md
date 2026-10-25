# Architecture proposal

Status: the personal-first modular monolith and six ADR directions are human
accepted. M0 through M4 are human accepted; see [M4 acceptance](runs/M4-acceptance.md).
M5-P1 is human accepted; see [its acceptance](runs/M5-P1-acceptance.md).
M5-P2 is owner accepted; see [its acceptance](runs/M5-P2-acceptance.md).
M5-P3 is owner accepted; see [its acceptance](runs/M5-P3-acceptance.md).
All M5 phases and the M5 milestone are human accepted. M6-P1 is merged and
human accepted; see [M6-P1 acceptance](runs/M6-P1-acceptance.md).
M6-P2 is human accepted; see [M6-P2 acceptance](runs/M6-P2-acceptance.md).
Twelve Data remains disabled and mock-only; live access and entitlement are blocked.
M6-P3 is human accepted; see [its acceptance](runs/M6-P3-acceptance.md).
Laravel SMTP remains disabled and synthetic mock-only.
M6-P4 is human accepted; see [its acceptance](runs/M6-P4-acceptance.md) and
[Drive policy](DRIVE-EXPORT.md). Google Drive remains disabled and mock-only.
All four M6 phases are accepted. The [independent M6 review](runs/M6-independent-review.md)
returned READY_FOR_OWNER; M6 and M7-P1 are human accepted; M7-P2 is authorized and in progress.
Deployment, live external delivery and later phases remain separate decisions.
Reporting composes existing source contracts inside a coherent database snapshot;
it retains dashboard evidence and rule/dismissal history without posting financial
transactions. See [dashboard policy](DASHBOARD.md), [cash availability](CASH-AVAILABILITY.md),
[assessment evidence](SHARIAH-EVIDENCE.md) and [manual FX decisions](FX-AND-RECONCILIATION.md).
Detailed module dependency directions below remain architectural proposals.

Use a Laravel modular monolith. Inertia, Vue 3 and TypeScript serve the application UI; PostgreSQL stores financial records. Filament is restricted administration. Exact selected versions are recorded in composer.lock, package-lock.json and [the runtime records](../../ops/development/README.md); PHP support is 8.5.x only.

| Module                | Responsibility                                                                                           |
| --------------------- | -------------------------------------------------------------------------------------------------------- |
| Identity              | Private registration, authentication, owner profile, policies, restricted administration and audit actor |
| Ledger                | Accounts, journal/posting rules, reversals, reconciliation and FX operations                             |
| Planning              | Categories, recurring commitments, goals, reservations and cash availability                             |
| Portfolio             | Assets, recorded investment events, positions, quotes and valuation                                      |
| Shariah               | Attributed evidence, methodology/version, uncertainty, review history and purification links             |
| Decisions             | Opportunities, thesis/review records and tranche plans                                                   |
| Reporting             | Dashboard, deterministic next actions, snapshots and owner-scoped exports                                |
| Integrations, post-V1 | Disabled-by-default adapters, imports, outbox and separately approved destinations                       |
| Intelligence, post-V1 | Consented read-only authorized tools, evaluation and usage controls                                      |

Financial rules belong in tested domain services. Controllers/jobs call application services. A financial operation commits its journal, investment events and idempotency record in one database transaction. Rebuildable projections and notifications happen after commit.

An account owns ledger relationships; a journal owns balanced postings. Goals annotate or reserve existing cash, never duplicate a transaction. Investment positions derive from recorded events. Valuation runs retain quote/rate identities, cutoff and calculation version. Read [the ADRs](ADRS.md) for proposed calculation contracts.

PostgreSQL integration evidence is mandatory; SQLite cannot stand in for transaction/concurrency behavior. No cloud provider, credentials or operational access is selected here.

## Proposed dependency directions

This dependency graph is a local architecture proposal, not a claim that the private source specifies every import edge. Shared money/currency primitives and authorization contracts sit below the domains and cannot depend on them.

| Module              | Allowed outgoing dependencies                                                      |
| ------------------- | ---------------------------------------------------------------------------------- |
| Identity            | Shared primitives; no financial module                                             |
| Ledger              | Identity authorization contracts and shared money/currency primitives              |
| Planning            | Ledger read/posting ports and Identity                                             |
| Portfolio           | Ledger posting/read ports and Identity                                             |
| Shariah             | Portfolio reads, Ledger posting/read ports and Identity                            |
| Decisions           | Planning, Portfolio and Shariah read contracts; Identity                           |
| Reporting           | Read contracts from the preceding modules; Identity                                |
| Integrations, later | Application-service ports; Identity; external adapters remain outside domain rules |
| Intelligence, later | Authorized Reporting/Decisions read facades only; Identity                         |

Cross-module operations belong to the initiating use case's application service: Portfolio application services coordinate a trade/event with Ledger; Planning application services coordinate funding/reservation annotations. They use one PostgreSQL transaction where records must commit together. Ledger never imports Portfolio or Planning, and read projections/notifications do not call back into posting rules.

Prohibit domain dependency cycles, reverse imports from Ledger into higher modules, calls from domain rules into provider adapters, and writes from Reporting or Intelligence. Model external adapters through inward-facing ports; core domains must remain runnable without enabled integrations. Public UI and admin call the same policy-checked application services. The owner must approve these proposed boundaries before implementation.

Purification application services compose the existing Ledger expense posting
port; obligations and allocation/release annotations never post cash. See
[purification recording policy](PURIFICATION.md).

## M6-P3 reminder delivery

Reminders compose current annotated dashboard episodes and explicitly selected incoming schedule versions. Full posted, unreleased matches suppress missed-contribution prompts; a goal funding review is not proof of a missed bank deposit. All rules/channels default off. Qatar calendar due/grace days are distinct from configured delivery timezone, local send time and quiet hours. Current and two preceding months are checked; grace is bounded to 30 days.

Immutable preferences, logical episodes, channel intents, claims, results and read receipts retain owner-scoped history. A bounded mutable pause control is the safety exception; its fixed-width generation fences stale settings forms. Pause works without adding evidence even at hard export capacity. Exact committed preference retries recover before current-token/capacity checks and never rewind the control. Admission is capped at512 logical episodes, two channels and three proved-safe attempts; retained history is not pruned to regain capacity. Complete owner export size is checked, and capacity is visible.

The bounded scanner invokes ID-only jobs synchronously, avoiding persistent queue-hint growth. In-app claim/publication is one transaction. SMTP commits a claim before handoff; only its creating stack holds the permit. Current verification/email, preferences, detail, matching/dismissal, timing and pause are rechecked under the owner advisory lock and current user-row lock. Uncertain claims never grant a new send, including restored evidence. Only proved no-handoff failures retry after one then five minutes, at most three attempts. SMTP has no exactly-once guarantee.

Laravel SMTP is owner-approved for synthetic mock-only testing. The reminder adapter requires the testing environment plus an actual mail fake, independent of Fortify mail. Generic content is a private-page link; explicit review detail adds only review type/due date. No balances, holdings, amounts or personal financial names are included. No live delivery, account or credentials are enabled. All seven new owner relations participate in the exact74-table catalog and populated encrypted recovery, with a seventh reminder reader fingerprint.

## M7-P1 read-only tool boundary

Intelligence composes only the explicit Reporting\CopilotEvidence facade, with
a closed enum registry. Reporting alone uses deterministic financial readers.
The service derives identity from the authenticated verified owner; no model or
HTTP owner argument is accepted. Coherent PostgreSQL reads begin before source
lookups, and consent generations fence interrupted work. Financial writes, SQL,
trading, sharing, arbitrary connectors and public method reflection are absent.
See [boundary policy](COPILOT-BOUNDARY.md). Provider selection is a generic port
with a safe unavailable default, tested using an injected synthetic contract.
No live transport, credentials, generated answers or live evaluation is claimed.

## M7-P2 bounded answers and live evaluation

Reporting owns typed answer queries, claim/topic enums, period policy, deterministic projections and the fixed owned source resolver. Intelligence owns the optional AnswerPlanner port and authenticated once-only/consent boundary; Integrations implements the OpenAI adapter inward through that port. Reporting does not import Intelligence. Model output selects one closed claim/mode/scope, with no money, arithmetic, IDs, URLs or tool executions. Server-authored text and typed facts attribute current cash/goal data, explicitly selected saved investment intervals and dated assessment sources. Unsupported periods return an inability rather than a substituted current balance. Hypothetical requests show recorded baseline facts only.

The planner receives only the explicitly confirmed question/document, which can themselves contain personal information; trusted source records and selected IDs remain local. No chat history is transmitted or persisted. Uncertain transport keeps the same current-page request and fields; a matching charged receipt settles it without repeating the call. Revoke/re-enable refreshes consent generation while preserving that pending request. Hard reload clears local messages and does not promise durable answer recovery. Redacted reservations/statuses remain exportable and restored answer-lane requests stay charged.

The default-off adapter uses dated gpt-5.4-2026-03-05 Responses, reasoning none, temperature0, store=false, no tools, strict JSON, one attempt,512 output tokens, an at-most30-second complete-body deadline and64KiB identity-encoded response ceiling. No actual key is committed or loaded by ordinary CI. The live entrypoint allows exact versioned synthetic questions only and the approved private sibling env. Its single private append-only budget ledger is independent of evidence output paths, includes the earlier access probe and failed/unknown reservations, and refuses deleted/replaced/truncated/symlinked history. The current owner-approved aggregate conservative reservation cap is USD5; all earlier failed/probe reservations remain charged. Evaluation alone has the8,192-input bound; production16,384-token admission and owner daily caps stay unchanged.

Evaluation basis B binds runtime, prompt, dataset, independent literal oracle, grader, adapter, tests and harness. Only the generated receipt's own acceptance-reference hash is normalized. Observed live R(B) is retained separately; final release source S includes its actual reference hash. Source changes invalidate B. CI validates that retained current-basis observation and deterministic integration again; it neither repeats live calls nor treats stubs as answer-quality evidence. The retained coordinator receipt is not a provider-signed attestation. No new signing key or external financial action is introduced.
