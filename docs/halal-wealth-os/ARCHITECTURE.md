# Architecture proposal

Status: the personal-first modular monolith and six ADR directions are human
accepted. M0 through M4 are human accepted; see [M4 acceptance](runs/M4-acceptance.md).
M5-P1 is human accepted; see [its acceptance](runs/M5-P1-acceptance.md).
M5-P2 quality/security verification is authorized and remains unaccepted.
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
