# M0-P3 local run

Status: READY_FOR_REVIEW candidate; human acceptance pending. Complete only this
phase. No merge, production onboarding, deployment or later phase started.
The final independent inventory review is retained privately and reported
separately; this record does not grant acceptance.

## Scope and ownership

The manager supplied the canonical [M0-P3 specification](../phases/M0-P3.md)
from the original plan v1.1 dated 10 October 2026. One implementation writer
holds the explicit claim on feat/m0-p3-identity-ownership. The branch began at
dev merge 6bac1581ade9023ecc30f27ec4c60a3575469d69 after human M0-P2 acceptance and
successful original [post-merge CI](https://github.com/hussain4real/halal-wealth-os/actions/runs/39044320828).
Prior phase/cycle/acceptance history remains intact; M0-P3 and milestone gates
are unaccepted. Three of six implementation/verification cycles were used.

## Changes and task evidence

- T1: Fortify public signup routes are disabled; retained registration view/action source is preserved. Initial owner onboarding is an interactive, validated, PostgreSQL-serialized console command. Existing nonempty user stores and noninteractive calls are refused. Login/logout/reset/verification/2FA/passkeys/password confirmation remain.
- T2: UserPolicy and explicit ownedBy queries restrict the current identity record to its actor. ID-based profile aliases and submitted id/owner_id/is_admin restrictions are covered with two synthetic users. Future owned financial models need their own owner_id scopes; none is implemented here.
- T3: Private Inertia shell, explicit empty/unavailable states, primary/settings navigation, keyboard skip link and associated live login/profile failures. Currency presentation keeps exact decimal strings and explicit codes without calculation, float conversion, rounding or inferred zeros.
- T4: Official Filament5.10.2 / Livewire4.4.7 locked and tested with the selected PHP8.5/Laravel13 stack. All existing resolved package versions remain unchanged. The retained web login/guard feeds a verified, explicit admin capability; persistent middleware and self-only read-only resources protect direct URLs and Livewire mounting. Capability is not mass assignable or editable through ordinary profile fields. Avatars remain local.
- T5: Fixed security-event vocabulary and numeric-only actor/subject context discard passwords, identities, tokens, recovery/session material, nested arbitrary context and financial values. Authentication/profile/onboarding events carry server-derived attribution. A failed attempt has a null actor; its attempted account may be a separate numeric subject.
- T6: [Identity/session conventions](../IDENTITY.md) document storage, cookie defaults, timeout, session rotation/invalidation, rate limits and the two-factor/recovery requirement before personal production use. In production, an unenrolled owner is redirected from dashboard to Security and denied the admin panel. Enrollment paths stay available. No production readiness or deployment evidence is claimed.

## Acceptance mapping

| Criterion | Observed local evidence                                                                                                                                                                                                                                                                                                    |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M0-P3-AC1 | Six anonymous protected-path cases redirect to login; GET/POST registration denial creates no account or authenticated session; noninteractive/second-owner onboarding refused.                                                                                                                                            |
| M0-P3-AC2 | Synthetic ownerB cannot view/mutate ownerA via route IDs; owner/id/profile capability submission is rejected; ordinary users cannot enter or mount admin resources. Even a capable admin's resource query/direct or forged record IDs remain self-only. Browser app/admin URL denial independently observed.               |
| M0-P3-AC3 | Retained login/logout/reset/rate tests plus new real reset throttling, session/CSRF rotation, structured redaction and authentication attribution tests pass. Test-only credentials/notifications and owned PostgreSQL are used. No production secrets are supplied; no universal production-log compliance claim is made. |
| M0-P3-AC4 | Chromium keyboard login, skip-to-content focus, profile navigation, Security/password confirmation and admin navigation pass. A real server uniqueness error is live, visible, aria-invalid and associated to its email field. Hydration and explicit unavailable state pass with no page errors/failed requests.          |
| M0-P3-AC5 | is_admin is server-only/nonfillable and prohibited in profile input. Verified capability is checked in ordinary and direct admin/Livewire paths, including revocation/unverified identity. No frontend control can grant it.                                                                                               |

## Verification

Full `./bin/verify --all` passed with PHP8.5.11/Xdebug3.5.3,
Composer2.10.3, Node24.19.0/npm11.9.0 and owned isolated PostgreSQL18.6:
**74 tests / 273 assertions**, **235/244 covered application statements**.
Pest runs --ci --no-tia with real reports and no required skipped/empty suites.
Formatting, PHPStan, frontend lint/types/build, both platform/lock checks,
dependency audits and guarded migration fresh/rollback/remigrate all passed.
Sixteen Node checks passed. M0-P3 requires discovery of all three identity,
onboarding and session/audit suites before claiming success. Financial coverage
thresholds and future suites remain inapplicable.

The two former public registration cases now assert the canonically required
private route denial; the other53 prior cases remain unchanged. Nineteen new
identity/session cases produce74 total. No assertion or required suite was
removed to hide a failure.

Separate local TIA recording passed all74 /273 in73.0s wall time. Unchanged replay
passed in4.4s; a native-output replay explicitly reported **74 replayed**, in3.6s
wall time. Cached results are not newly executed tests. The output formatter
was disabled only for that process to observe native replay metadata; no
runtime, trust, network or security configuration changed. Existing PHP/page
selection and conservative full recording for shared frontend changes remain.
Full uncached coverage evidence above is independent of TIA.

Nine strict Chromium checks passed against a loopback-only local server with
normal configured proxy/TLS verification. Instrument Sans loaded. Synthetic
fixture users were removed and the server stopped. Screenshots, raw logs,
reports, runtime identity, source inventories and exact authorization evidence
remain private outside this checkout. No M0-P3 hosted CI was run or claimed.

## Bounded cycles and independent review

1. Restore canonical text/claim; implement private identity, admin, audit and shell. Initial targeted result20/21 found ineffective reset-throttle attachment; lint also found two unawaited test promises.
2. Correct the throttle in the web middleware path and test promises. Targeted21/106 and PHPStan passed. Strict Composer validation rejected the exact root Filament constraint; the same locked official release was retained under its patch-line constraint. Independent review found one P2: a failed login's target was incorrectly attributed as its actor.
3. Correct failed-login actor/subject attribution and its regression expectation; refresh the lock content hash without version changes. Full uncached gate, browser verification and TIA record/replay passed. Final inventory review and sanitation are the last local review checkpoint; no self-acceptance occurs.

INV-10 is covered by the owner/admin tests and browser denials. INV-12 is
covered by separate observed uncached, TIA and browser evidence with no inferred
production/hosted outcomes. Currency display does not implement financial
calculation; the remaining financial invariants remain future obligations.
Initial audit logs are structured/redacted local events, not an immutable
compliance store. Personal production, mail delivery, real owner provisioning,
external accounts, deployment, stage/main promotion, milestone acceptance and
later phases remain excluded.
