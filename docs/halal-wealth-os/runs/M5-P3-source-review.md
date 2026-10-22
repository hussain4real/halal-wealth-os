# M5-P3 independent source review

Outcome: no remaining blocking P1/P2 in the reviewed export/security, recovery,
or verification/CI surfaces. Three independent read-only reviews inspected
separate concerns; the coordinator remains the sole implementation writer.
Private receipts identify the examined files/hashes and their scope. This source
review does not replace runtime, browser, hosted review/CI or owner acceptance.

## Export and private-release security

The review traced all 48 financial sections, both indirect opening ownership
paths, trusted embedded-evidence writers and supported cross-owner constraints.
Export establishes its own repeatable-read, read-only UTC snapshot, checks the
reviewed public schema before reading, emits exact scalar strings/stored JSON
text, completes a private capped spool before responding and removes it after
response or failure. Profile selection excludes authentication material. Route
authentication, verified email, production confirmed two-factor enrollment,
recent password confirmation, query rejection and throttling were inspected.

Minimal health output, actual migration/catalog checks, closed registration,
debug-off errors, explicit admin capability and real Fortify enrollment/recovery
checks were reviewed. The catalog does not validate numeric typemods, constraints
or embedded JSON versions. No demonstrated foreign-row/credential read was found
through the supported writers; the narrower embedded JSON defense is described
accurately rather than presented as arbitrary compromised-database sanitization.

## Recovery and operating procedures

The review traced authenticated final-frame archive handling, bounded frames,
wrong-key/corruption rejection and full authentication before pg_restore. The
rehearsal uses fresh random databases on the guarded disposable server, verified
per-run cleanup markers, matching PostgreSQL18 clients and transactional restore
with triggers/constraints enabled. Full table contents, existing cash/position/
goal readers and assessment records/references are compared. A new write and
specific restored posting-immutability failure are checked. Populated downgrade
is attempted transactionally, must refuse and must preserve the restored capture.

Cleanup now attempts each owned database independently and always closes/removes
private archive files, reporting failure if any marked database remains. The
operational file tool refuses unsafe permissions and overwrite, removes failed
outputs and does not connect to any database. Private plaintext spooling/crash
residue, APP_KEY/backup-key custody, unselected production retention/RPO/RTO and
separate deployment signoff are retained as explicit limitations. The synthetic
fixture does not claim every table or business scenario is populated.

## Verification and publication

The initial recovery-report consumer could stamp a stale structurally passing
receipt as current. The correction binds actual producer source-before/after
and a unique full-run ID; focused/missing/stale/mismatched receipts reject.
Finalized JUnit/source mismatches also reject without receipt mutation. The
consumer verifies build assets, locks, source/JUnit and migration/recovery proof.
All predecessor exact selectors, fixtures, scoped manual hashes, coverage floors,
full failure-propagation and HTTP/workload gates were retained. PostgreSQL CLI
wrappers use the same pinned service; sodium is a required runtime extension.

Independent publication review found no original private financial input, secret,
real owner record or raw archive in the reviewed public additions. Fixtures use
synthetic values through existing writers. The devnull job remains unchanged,
including public dev-push/original-attempt/exact-merged-PR eligibility and its
pinned signing action. Failed originals and later key recognition are separate
facts; no signed publication is claimed before observing its actual outcome.

## Hosted exact-head release correction

The first hosted review found a P2 missed by the initial local source review:
release finalization could emit PASS with a dirty checkout while recording
HEAD's commit/tree. The historical local diagnostic passes remain functional
evidence, not valid clean release records. Cycle 4 rejects any tracked/untracked
change before finalization writes, removes a stale generated release record on
a failed attempt, and independently checks current clean HEAD/tree in final
acceptance. Actual temporary Git fixtures test clean, modified-tracked and
untracked states. A real dirty-checkout finalizer call returned exit2, removed
the stale PASS record and left its recovery receipt unchanged.

The intentional failure probe still runs its expected failing Pest case before
finalization; only the clean restored baseline can issue a passing release
record. Independent correction review and new clean exact-head full CI/fresh
hosted review are required before merge.
