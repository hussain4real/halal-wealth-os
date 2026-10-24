# M6-P4 local run

The owner accepted M6-P3 and authorized canonical M6-P4 through dev PR, gated normal merge after original CI/fresh review, then original post-merge CI and existing signed public progress verification. Six new scoped cycles; root is sole implementation writer and reviewers stay source-read-only. Current preparation does not consume an implementation cycle.

The owner explicitly approved drive.file plus openid/email scopes for disabled synthetic mocks only. No account, consent request, credentials, live export or permission change is authorized. Actual live behavior remains blocked until separately authorized and observed.

## Scoped implementation history

Cycle1 introduced the disabled connection/report queue and passed71 scoped tests, types/build and static analysis. Independent review required OAuth attempt fencing, refresh persistence, scanner fairness and crash/uncertainty corrections. Original migration/trigger/test setup failures were preserved privately.

Cycle2 corrected those lifecycle paths and passed89 scoped tests/873 assertions, including separate PostgreSQL backends and populated recovery. Review identified competing revoke claims and loss of uncertainty after local stop; both remained open rather than being relabeled passed.

Cycle3 introduced the exclusive revoke lease, stopped-unknown outcome, bounded hard-export-capacity cleanup and actual restored report/vault/epoch checks. Its first scoped run passed91/92 tests; the remaining assertion scanned before its documented retry time. Independent source review also identified a same-instance UI request-identity reset issue.

Cycle4 corrected request UUID/draft rotation and the test timing/race contract.92 scoped tests/895 assertions and three actual-helper request-state tests passed. Independent UI review found stale report/late-response consent, retained account acknowledgement and feedback/outcome gaps.

Cycle5 corrected that consent/recovery UI scope. Actual desktop/mobile light/dark browser checks and independent source/pixel review are recorded in [rendered review](M6-P4-rendered-review.md). Backend access/export findings are source-resolved. Native TIA, restored-source selection probes and the clean uncached release gate are still to be recorded separately; no hosted delivery, phase acceptance or live integration is claimed by this local summary.

Cycle6 moved callback credential handling before session/authentication/throttling. Guest, unverified, production two-factor, throttled and stale-session callbacks return safe failures without credential-bearing intended/previous URLs. A valid callback retains its actual start cookie and consumes the state without saving the code. The first complete native baseline exposed a stale fixture association and a direct empty-dashboard roundtrip dependency; the fixture's established three-family contract and every prior financial-byte assertion were preserved when correcting them.

The frozen-source affected run passed135 tests/1,291 assertions, including five early callback privacy cases, the successful authenticated callback, actual separate PostgreSQL races and populated restore. Static analysis passed. Final configured evidence contains122 requirements and638 exact selectors. Fresh native TIA/probes and clean full release proof remain separate; all earlier partial, failed and interrupted attempts remain private. No hosted result or acceptance is claimed here.

The fresh final-source native TIA baseline passed1,283 tests/9,444 assertions. This is native impact-recording evidence and does not substitute for the clean uncached coverage/release gate. The earlier incomplete/native attempts remain failures in private evidence. Local commit preparation preserves the current source and all six-cycle history; publication and merge still require their separate gates.

The cycle6 clean uncached release and all seven strict artifacts passed1,283 tests/9,444 assertions,80 financial floors,122 requirements/638 selectors and79-table/eight-reader recovery. PR #24’s subsequent fresh review found three P2 issues; that local pass did not establish those new cases were correct. The owner authorized two additional cycles, total ceiling eight, preserving all preceding failures.

Cycle7 reproduced all twelve initial recovery cases as failing before the fixes and passing afterward. The expanded Drive/transport scope passed97 tests/635 assertions: malformed or oversized post-create verification retains unknown/same-file reconciliation; transient identity reads preserve eligibility and accepted credential rotation; rejected token grants attempt bounded revocation and explicitly report unconfirmed cleanup. Credential-stage rejection remains distinct from transient identity reads, and credential-bearing trace arguments are redacted. Further independent, rendered, TIA and full release gates remain separate.

The final cycle7 affected scope passed162 tests/1,599 assertions, including existing privacy, owner export, separate-backend concurrency and populated restore. A final transport regression also proves bounded prior-token cleanup when newly returned credentials are unusable. Independent source and rendered/sanitation review passed its stated scope;27 new exact selectors bring the configured inventory to122 requirements/665 selectors. The fresh TIA baseline/probes and clean full release remain separate pending gates.

The final corrected-source native TIA baseline passed1,310 tests/9,752 assertions. Actual Vue and functional PHP probes selected the affected test files and restored the source byte-for-byte; cached replay is recorded separately. This pre-publication summary does not assert a full release result for a future commit. The final clean uncached release artifacts must identify the actual published commit, tree and executable source. The PR and original CI provide the current delivery results; acceptance remains a human decision.
