# Validation contract

M0-P1 checks documentation, JSON, requirement IDs/dependencies, links, publication hygiene and diff hygiene. There is no application, so PHP/JS tests, builds, migrations, browser flows and financial coverage are NOT_APPLICABLE at this stage.

The proposed project commands ./bin/verify --phase Mx-Py and ./bin/verify --all belong to M0-P2. They are not native Codex commands and do not exist in this checkout. Do not fabricate success from a missing command.

## Checks for this package

- Parse every planning JSON file.
- Confirm 8 milestones, 25 unique phases, 150 task IDs, 125 acceptance IDs and 12 invariant IDs.
- Verify all dependencies exist, the graph is acyclic and each phase follows its predecessor.
- Verify only M0-P1 has explicit human acceptance, all later phases/milestone gates remain unaccepted, active_run is null, bootstrap remains false and publication authority is limited to the final reviewed planning snapshot.
- Resolve every relative Markdown link within this checkout.
- Inspect every proposed file for personal financial values, credentials, private identifiers and raw source documents.
- Check whitespace, final newlines and git diff --check. Before the initial publication all files are untracked, so normal git diff alone cannot cover content; explicitly inspect untracked files too. After staging verify the exact index tree matches the reviewed inventory.
- Record exact command exits and content hashes in private local evidence.

## Later gates

M0-P2 must select locked tooling and create a real failure-propagating harness plus CI. Later checks cover domain boundaries, PostgreSQL atomicity/concurrency, ownership, missing-data cases, production build and disposable migration tests. Financial calculation coverage and performance gates are release criteria, not current evidence.

No cloud, external provider, live-model or deployment gate has run. Missing mandatory services are blockers, not passing tests.

## Reconciled canonical release requirements

The verified kit supplies 18 golden scenario families, F01-F18. The originals contain private profile examples and remain in owner-only source evidence. They are canonical calculation evidence, not an approved public fixture set. Before implementing a related domain phase, provide the authorized worker private source access and approve any synthetic public-fixture translation that preserves every required scenario and expected invariant. Never silently substitute invented amounts or weaken an assertion.

By M5-P2, financial calculation services require at least 90% executable line coverage: Money, Posting, FX conversion, Reservation, Goal projection, cash availability, cost basis, holdings projection, valuation and purification. Report exact included paths; a missing coverage driver or omitted service blocks the gate. Coverage never replaces monetary, concurrency, ownership or missing-data scenarios.

The M5-P2 warm-dashboard target is server p95 at most one second over 30 sequential runs, using 10,000 journal postings and 100 holdings with no live external calls. Record environment, fixture, warm-up, query count, measurements and cache state. This is a future acceptance target, not observed performance.

The later harness must fail for unknown phase IDs, zero required discovered tests, missing tools or unavailable required services. Full checks cover implemented modules and applicable prior accepted tests; unimplemented roadmap tests cannot be reported as passing. Use disposable PostgreSQL resources and never reset production/shared databases.

M6 adapters may be reviewed disabled with deterministic mocks, but live connectivity remains NOT_TESTED/BLOCKED without separate destination/account authorization and an observed smoke check. M7-P2 requires separately recorded live-model evaluation; mocked transport is not live answer-quality evidence.
