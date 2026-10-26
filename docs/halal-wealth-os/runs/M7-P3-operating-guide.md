# Optional services: owner operating guide

Manual V1 remains the fallback. Opening this guide or resuming a control does not deploy the application, grant provider access or accept M7-P3/M7.

## Stop and recover

Open **Optional feature controls** and use **Pause all optional work**. This changes existing owner policies without allocating a new history row, including at evidence capacity. Absent policies already mean stopped. Each actual external handoff checks the owner stop and generation under the owner lock. A call already sent can complete; pause cannot cancel a provider-side action.

A new owner first saves stopped limits, then explicitly resumes a channel. Changing a limit never clears usage. Refresh before resuming after another tab changes settings. Untouched fields adopt saved settings; independently edited values remain visible for an explicit save. Current consent, verification, provider mode and native recovery restrictions still apply.

| Channel                 | Reserved daily unit                                     | Recovery boundary                                                                                                                                       |
| ----------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Copilot                 | Configured native token reservation, including previews | At most10 admissions and65,536 reserved tokens; receipt/status recovery does not repeat execution or retain completed answers                           |
| Market                  | One credit for participation in a shared physical fetch | One fetch may serve several consenting owners; failures retain charges and previous observations remain visibly stale                                   |
| External reminder email | One potential handoff                                   | Only proved no-handoff failures retry, at finite native intervals; uncertain SMTP is inspected, never automatically resent                              |
| Personal Drive          | Full fixed action HTTP envelope                         | Connect3, prepare4, review4, confirm5, export/reconcile8, revoke1; unused slots stay charged; unknown uploads reconcile the same preallocated remote ID |

The page shows current saved limits, UTC daily use, retained charges and pending/uncertain work. A lower limit can immediately exhaust remaining capacity. Up to2,048 operational reservations are retained per owner, across channels; native lower ceilings and full-export capacity also apply. No failed/uncertain allowance is refunded. Local Drive disconnect stays available when remote cleanup cannot be confirmed within its budget.

## Private monitoring and retention

Operational summaries contain counts, fixed outcome categories and coarse processing time. They exclude prompts, documents, answers, financial figures, email destinations, provider payloads and raw error messages. Import counts sample at most the latest three retained previews. Market monitoring reads up to four bounded pages and labels a partial summary. Use the linked feature page to inspect owned detailed recovery evidence.

The selected1–30day retention expires only disposable hourly operational metrics. Configuration and completed outcomes prune that owner's expired buckets; `operations:prune-metrics` performs operator maintenance and is scheduled daily. The scheduler must actually be running for idle buckets to expire. Stop it only under an explicit operating decision. Usage charges, financial records, assessment versions, imports and native recovery evidence are not retention-reset mechanisms and remain retained. Profile deletion is refused while owned financial or operational history remains; it never deletes retained evidence to regain access. Export contains the owner-scoped operational relations; encrypted operational backups remain separately protected.

Copilot sends only the explicitly confirmed question/document and fixed plan schema to its configured model. Trusted financial source records and selected IDs stay local; no chat history is sent or persisted. Browser memory holds a current question only for explicit request recovery; reload clears it. `store:false` is requested, but this is not a promise about the provider's independent retention. Do not enter real personal financial material without a separate disclosure/access decision. The release evaluation uses only fixed synthetic cases and a private, append-only conservative reservation ledger; its cap is not billed expenditure or an application daily dollar budget.

## Operator stop and safe rollback

`OPTIONAL_OPERATIONS_PAUSED` is a fail-closed process configuration stop. Apply it through the approved configuration procedure and restart long-running workers; it is not a distributed instantaneous cancellation switch. Owner pause is the durable hot stop checked at each send. Manual account, journal, portfolio, goal and assessment pages remain available.

Operational rollback means **disable optional services in the matching current build**. The release rehearsal verifies unchanged retained tables/readers, foreign-owner policies and a real balanced manual posting after all four channels stop. It does not prove an older81-table binary can run the current84-table schema. For full recovery, restore a matching code version and encrypted backup into an owned empty target. Wrong keys fail before plaintext restore; populated downgrade refuses before DDL. Never delete history, reset budgets or drop populated relations to regain access.

Market/TwelveData, Laravel SMTP and Google Drive remain disabled for live use and synthetic mock-only. Scope approval is design approval, not an account connection. Model transport is optional, disabled by default and separately consented. Provider outage, revoked access, a retained ceiling or uncertain handoff requires inspecting its existing recovery path; no generic retry, new remote ID or duplicate post is authorized by this guide.

## Release dossier and owner decision

The seven `m7-p3-verification` CI artifacts contain actual JUnit, coverage/floors, native HTTP, mapped requirements, encrypted recovery/operational rollback and release-record identities. The release record identifies the exact clean commit/tree/source, built assets, locks, migrations, pinned model/policy and current actual synthetic40-case receipt. Preparation explicitly says `LIVE_GATE_PENDING` and cannot substitute for a release record. CI consumes a retained measured evaluation; it does not call the paid model.

Local release, fresh exact-head review, original PR CI, normal dev merge, original merge CI, signed progress publishing, anonymous public body and human acceptance are separate gates. Deployment, live integration entitlement, retention/key custody, production recovery targets and phase/milestone acceptance require explicit owner decisions. No autonomous money movement or unattended deployment is authorized.
