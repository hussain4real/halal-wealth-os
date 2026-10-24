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
