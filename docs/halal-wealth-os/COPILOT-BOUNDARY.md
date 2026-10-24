# Read-only financial evidence boundary

M7-P1 exposes exactly cash availability, goals, holdings, saved valuation evidence
and Shariah assessment history. It has no financial write, trading, payment,
sharing, raw SQL or arbitrary URL tool. Every query passes the same strict schema
for direct and provider-proposed calls; additional owner/actor arguments fail.
Server authentication selects the owner, including for administrators.

Reporting composes existing exact financial services in a coherent PostgreSQL
snapshot. Amounts retain currency and exact strings, missing values stay null,
and source/as-of/capture metadata is explicit. Sources are selected references,
not an exhaustive transaction inventory. Goals are current state with a planning
month context. Histories/pages show continuation. Saved valuations preserve their
original captured time; the new envelope generation time does not refresh them.
Aggregate accounts have no look-through. Shariah evidence is not certification.
No model performs arithmetic or invents missing financial evidence.

Consent defaults off; the boundary defaults disabled. Each new request requires
current owner consent, and retained generation fences revoke/re-enable races.
Revocation uses one bounded control row and remains available at history capacity.
The fixed registry is trusted code; question/document text and retained prose are
untrusted data. Required structured projections omit private names, notes, URLs
and actor metadata. No text can define a new tool or change authorization.

A generic optional provider may propose one strict query; it cannot execute one.
M7-P1 ships a safe unavailable default and synthetic contract tests; no live
provider, key, account or model evaluation is selected. No financial result is
sent to a provider in this phase. Answer generation/evaluation belongs to M7-P2.

Limits are configured in config/copilot.php. Requests reserve a conservative
token ceiling before handoff; input, schema, provider proposal and evidence have
byte ceilings covered by that reservation. These are admission ceilings, not
measured or billed model tokens. Requests and reserved tokens have UTC daily
caps. One database clock instant, read after the owner lock, defines both the
UTC admission window and charge timestamp; host clock skew or a transaction
waiting across midnight cannot reset consumed usage. At most 512 retained requests per owner; history is not pruned to regain
capacity. New controls/charges require actual complete-export headroom under the
shared owner lock, with 64KiB reserved for bounded completions and revocation.
Admission rollback preserves earlier receipts. Large evidence fails unavailable without silent truncation. Core
financial pages remain available.

The request UUID is once-admitted. Failed/interrupted requests stay charged;
replaying that UUID reports already charged, never a second handoff. A new explicit
preview uses a new UUID. No automatic resend or background continuation exists.
Traces retain only owner-bound opaque UUIDs, enums, consent generation, time and
reserved counters, never questions/documents/results or raw provider errors.
Tool endpoints force JSON before authentication/validation, avoiding session
flashing; copilot responses are private/no-store. Consent and charged request
metadata participate in strict owner export and encrypted recovery. Restored
pending requests never run automatically.

M7-P1 remains unaccepted until the human decides after independent delivery.
Live provider access, later phases, deployment and permissions are separate.
