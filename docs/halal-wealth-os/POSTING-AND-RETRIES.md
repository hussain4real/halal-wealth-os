# Atomic posting and retry contracts

Status: M1-P2 implementation under independent review; not phase acceptance.
No FX, live banking, reservations, external event delivery or deployment.

## One financial write boundary

PostingService handles income, expense, same-currency transfers, opening
consumption and linked reversals/corrections. Every operation is an owned
PostgreSQL transaction containing its journal, all signed BIGINT legs and
successful retry identity; opening consumption adds its immutable unique link.
Pending is the internal pre-posting state, not an editable user draft. A
persisted empty pending header has no balance effect and cannot report posted
success. Pending financial legs cannot survive commit. Posted and reversed
journals must contain at least two legs and balance exactly per currency.

Composite foreign keys bind each leg to the same owner, account and currency.
Deferred constraint triggers validate aggregate totals and exact per-account
reversal cancellation at the actual outer commit. Financial values, receipt
references and original metadata cannot be updated/deleted. Only pending to
posted and posted to reversed state transitions are permitted, with linked
history checks. All application-service movements in this phase share one
currency; equal numbers in different currencies cannot cancel one another.

Income debits a personal account and credits internal income; expense credits
a personal account and debits internal expense. A transfer debits the owned
destination and credits the distinct owned source; it produces no income or
expense account movement. Opening balances use internal equity. Internal
accounts are owner/currency scoped and excluded from net worth. The service
creates them lazily; it does not choose another owner's account.

## Retry, authorization and concurrency

Each successful owner/key pair is retained without expiry. Account ownership
is resolved before looking up a cached result, including admin users. Fresh
locked rows govern new writes. An identical normalized consequential payload
returns the same durable journal ID and reference; its current displayed
status may reflect a later reversal. Different details under the same key
return a validation conflict. Failed transactions leave no successful key.
Reusing a consumed opening with a different unused key is rejected with linked
history guidance; it does not return an unbound successful request identity.

An owner-scoped PostgreSQL advisory transaction lock serializes that owner's
financial writes. It is retained until the outermost commit, and different
owners use separate keys. Accounts are also locked; an archive that commits
before posting validation prevents the new movement. Uniqueness and composite
foreign keys are additional database boundaries. Hash collisions can only
serialize unrelated work. This deliberately favors simple correctness over
parallel throughput within one personal workspace.

The service is composable inside a caller transaction. It never commits that
caller's transaction early. Reversal plus replacement is one transaction: a
failure leaves the original posted, with neither correction half applied.
A single original can have one reversal. A reversal record cannot itself be
reversed; a later replacement can be corrected through its own linked history.
Archiving prevents new movements/replacements; necessary reversal of existing
posted history remains possible. Authorized exact retries remain readable
following archive, without creating additional movements.

## Exact amounts, dates and completeness

Positive income, expense and transfer amounts are required. Zero openings are
retained and explicitly postable. Negative opening balances and liability
signs are preserved. No overdraft or available-cash restriction is invented in
this phase. The recorded timestamp is immutable UTC; an effective calendar
date from year 0001 through today may describe a historical movement. Future
plans are not posted here. Reversals use the current effective date; a replacement uses its explicitly
chosen date. Current balances include all committed movements. Historical
statement-cutoff interpretation and reconciliation belong to M1-P3.

Money keeps its full original signed range. Negating MIN mathematically needs
MAX+1; the posting service splits that opposite into MAX and +1 legs, each
within the accepted BIGINT/Money range. A reversal uses the same approach.
Account balances sum all original and reversal legs before checking the final
range. An intermediate/internal aggregate outside the displayable Money range
is explicitly unavailable/out_of_range. Exact journal movements can still be
shown as strings; no Money input range is silently expanded or narrowed.

An opening remains immutable and awaiting confirmation until explicitly linked
to a posted journal. Unresolved openings keep the complete balance unavailable,
even if other transactions exist. No request is silently posted by account
creation, migration or a balance read. Once consumed, a reversed opening stays
linked to its reversed history and cannot be posted again.

## After commit and recovery

JournalPosted is emitted only after the outermost successful commit. Outer
rollback discards callbacks. The durable journal ID is the downstream reader's
identity: a projection retry must rebuild/read the journal, never repost it.
No external consumers are connected. In-process dispatch is best effort, not
a durable outbox or exactly-once delivery guarantee. A listener crash after
commit can leave a missing downstream update and an ambiguous HTTP outcome.
The ledger remains committed; a same-key retry safely returns its receipt and
does not re-emit or duplicate the movement. Future delivery infrastructure must
supply its own deduplication/reconciliation policy.

The entry form retains its request identity and values in Inertia's per-user
history state. Processing disables repeat submission; server idempotency also
protects direct/repeated requests. A lost response is described as uncertain,
with safe same-request retry and history recovery. Success opens a durable
receipt, not an optimistic client-calculated balance. Receipt references are
escaped plain text; no upload, remote URL fetch or bank action is performed.

## Verification boundaries

PostgreSQL tests observe two independent backend PIDs concurrently waiting at
a real advisory-lock barrier, then release them to race. Separate tests cover
an archive row-lock race, commit-time balance constraints, outer transaction
rollback/events, injected failures, owner-scoped retry conflicts, unique opening
consumption, retained reversals and complete exact aggregate cancellation.
Functional server, browser, TIA and full uncached evidence are reported in the
phase run only after actual execution. Canonical original fixtures stay private;
public regression scenarios use sanitized synthetic values.
