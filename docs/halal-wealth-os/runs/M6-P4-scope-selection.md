# M6-P4 approved scope selection

The human owner approved exactly `openid`, `email` and `https://www.googleapis.com/auth/drive.file` for disabled, synthetic mock-only M6-P4 tests on24 October2026. This records a scope design, not an OAuth consent, connected account, application registration, credential creation or live export.

Google documents [drive.file as per-file access](https://developers.google.com/workspace/drive/api/guides/api-specific-auth). [OpenID identity](https://developers.google.com/identity/openid-connect/reference) distinguishes stable account `sub` from mutable display email. The confirmation must use the actual verified provider identity and actual target; application login email or a typed destination cannot substitute for that evidence. No account is assumed connected. No `profile`, whole-Drive, public sharing or email-ingestion scope is approved.

Live consent, credentials, entitlement, upload and account/destination behavior remain blocked until separately authorized and observed. Mock tests do not establish those live results. Drive export stays separate from the application's encrypted operational database backup policy.
