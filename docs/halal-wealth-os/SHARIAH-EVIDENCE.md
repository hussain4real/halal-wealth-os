# Attributed assessment evidence

M4-P1 records claims and uncertainty. It does not issue fatwas, verify a source's
authority, certify securities or supply screening thresholds. No classification
is inferred from an asset name, platform or existing holding. A missing source
assessment is pending review.

## Attribution and immutable revisions

Each record retains its owner, authenticated actor and administrator context,
source/provider, methodology version, supporting reference text, optional HTTPS
source link, effective date, optional review date, reason and recorded timestamp.
Business-activity findings, financial-screening findings and personal context
remain separate. Supporting references display as escaped text; links are never
fetched or uploaded automatically.

Attributed source claims, personal notes and retained AI suggestions are explicit
provenance categories. Notes and AI suggestions remain pending and do not enter
source-backed classification or conflict selection. No AI generation is enabled.
A non-pending source classification requires supporting references and an
identified methodology version. An entered source claim is not independently
verified by the application.

Every revision appends a new record; PostgreSQL refuses updates and deletion.
An independent source series keeps its provider, provenance and exact owned
asset reference. A source/methodology correction retains prior evidence and
authorship. Different providers use independent series; no global latest row
silently displaces another authority. Only one successor may revise a record.

## Exact references and time

Assessments bind to an exact asset reference version and retain its family.
Revising an asset identifier or other metadata never silently transfers compliant
evidence to a new version. Family history exposes the earlier attribution and
exact reference; a new reference needs explicitly recorded evidence. A server
context rejects an uncommitted form after the family reference changes.

The as-of cutoff is an inclusive UTC instant meaning **known then**. Evidence
must have been recorded by that instant and effective by its UTC date. Select
the highest eligible version of each independent series after applying both
conditions. A later recorded backdated correction never leaks into an earlier
known-then view. A revision cannot move its effective date before its predecessor.

Review due dates may be in the future. No fixed review interval is invented.
A review becomes overdue after its due date, rather than on that date. This flag
is evaluated at the selected cutoff; the recorded source classification remains
visible and is never silently renewed. A missing review date remains explicit.

## Decision guidance and factual ownership

Different current source classifications produce a visible conflict. No evidence,
pending/questionable/non-compliant classification, conflict, overdue review or
missing review date leaves guidance unresolved. Even unanimous current compliant
claims describe only what the recorded sources claim, with their methodology
and evidence; the application does not make an automatic buy recommendation.

An assessment never creates, changes or removes a journal, cash movement, unit,
book cost, saved valuation or existing holding. The factual opening/trade workflow
remains available with any assessment status. It links to assessment evidence
and explains unresolved risks separately from recording what actually happened.

## Ownership, retries and administration

Owner and administrator evidence navigation uses the same authenticated,
verified, production-two-factor-protected application workflow. Administrator
status grants no access to another owner's assessment, history, asset or export.
The server derives owner/actor attribution; clients cannot submit these fields.
Assessment history is retained in the domain store, not in restricted security
logs that deliberately exclude private evidence.

The owner transaction lock serializes admitted writes. Committed same-key,
same-payload retries recover before stale-context or successor checks. Changed
payloads cannot reuse the key. Independent PostgreSQL backends verify duplicate
requests, competing revisions and coherent reads. History pages are bounded;
current-source selection and authenticated complete exports remain complete.

Purification obligations/payments, opportunity/tranche planning, automated source
fetching and religious rulings are outside this phase. Canonical requirements are
in [M4-P1](phases/M4-P1.md); observed results belong in its
[run evidence](runs/M4-P1-local.md).
