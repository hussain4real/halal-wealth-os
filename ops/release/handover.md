# Private V1 owner handover

M5-P3 prepares an inspectable release. Engineering readiness, phase acceptance,
M5 milestone acceptance and deployment are separate decisions. Production
hosting, real data entry, live integrations and public launch remain pending.

## Release and migration checklist

1. Record the exact reviewed commit/tree and `build/release-record.json` from the
   passing run. Match the asset manifest, lock hashes and ordered migrations.
   Finalization refuses tracked or untracked changes. Commit reviewed source
   before the final full gate; focused editing tests do not issue a release
   record. Only a clean reviewed head can be merged.
2. Require full uncached tests, coverage floors, HTTP/security/workload checks,
   acceptance map, encrypted recovery, independent source/rendered review and
   fresh exact-head hosted review/CI. Do not replace a failed original workflow
   with a rerun without the owner's authorization.
3. Inspect the selected environment against configuration.md and run
   `php artisan release:check`. Confirm TLS, real mail delivery, private database
   grants, storage, secrets custody and offsite restore evidence separately.
4. Before any authorized deployment, stop writes/enter maintenance as appropriate,
   take and verify an encrypted backup, then apply migrations in the recorded
   order with `php artisan migrate --force`. Do not run `migrate:fresh` on retained
   data. Record before/after migration IDs and health checks.
5. If rollout fails, stop traffic/writes. Choose a reviewed forward correction or
   restore the verified backup into a separately inspected empty replacement
   environment with its matching code/APP_KEY. Reconcile before resuming traffic.
   Several retained-history migrations deliberately refuse populated downgrade;
   an empty-schema rollback smoke test does not prove populated rollback safety.
   Code rollback is safe only if its schema/reader contract remains compatible.
6. Require an explicit owner deployment decision identifying environment,
   immutable release, backup receipt, retention/RPO/RTO, rollback owner and scope.
   No checklist tick or green CI grants that authority.

## Enter and inspect data

- Use private onboarding for the initial owner. Enroll and confirm two-factor
  authentication; store recovery codes privately and replace used codes through
  security settings. Confirm password before sensitive changes or full export.
- Enter account/currency and source-backed opening amounts. An opening intent
  remains pending until its explicit posting; inspect the resulting journal.
- Record actual cash movements once with retained references. Correct mistakes
  through reversal/replacement rather than rewriting posted history.
- Keep forecasts, goal reservations, opportunities and actual money distinct.
  Release reservations explicitly and inspect both retained histories.
- Enter positions, units, prices, FX observations and assessments with their
  source and effective date. Missing or stale evidence stays visibly partial;
  Shariah references are attributed records, not application certification.
- Review reconciliation and the dashboard before relying on a summary. A saved
  snapshot retains evidence and does not replace current source records.
- In Settings → Data export, download the complete versioned JSON for your owner.
  It includes retained versions, archived records and released history. Keep it
  private. It excludes authentication material, other owners, logs/files and
  operational infrastructure, and is not a restorable database backup.

## Known limits

The export catalog compares all public tables and column names/types/nullability;
it does not compare numeric precision, constraints or JSON schema versions.
JSONB evidence is exported as its stored JSON text; it cannot reproduce original
submission whitespace or object key order. All non-null database scalar values
are strings, preserving large IDs and exact decimals. Export size is capped at
64 MiB; larger exports fail completely with a generic retry/support response.
No upload/file store, provider import, live bank/market/AI connection, production
penetration test or production performance guarantee is implemented here.

Original M5-P2 application CI passed, while its original progress publisher failed
before the owner refreshed service key recognition. Preserve that failure. The
next authorized original dev merge checks application CI and signed /dev/null
submission separately. A submission awaiting moderation is not a publicly visible
post. Progress contains only public merged-PR metadata.
