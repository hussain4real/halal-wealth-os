# M1-P2 local implementation and verification

Status: READY_FOR_REVIEW, not accepted. Four of at most six implementation
cycles used; prior phase histories remain preserved. The owner accepted M1-P1
and separately approved M1-P2 implementation, independent review, sanitized
draft PR to dev and normal merge after exact-head reviews and actual CI pass.
The sole coordinator owns the feature branch based on verified dev merge
10ac3e3f79470bcd5585d6f2e0af87a8451aa151. M1 and all later phases remain unaccepted.

## Delivered behavior

One owner-authorized service records income, expense, same-currency transfers,
explicit opening consumption and linked reversals/replacements. PostgreSQL
transactions, owner-scoped retry identity/fingerprint, owner advisory locks,
fresh account locks, composite foreign keys and deferred aggregate triggers
protect atomicity and history. Immutable opening intents gain one unique posted
journal link; migrations never post them automatically. Reversed originals
remain in balance derivation beside their opposite movements. The original
Money range, including MIN, is preserved with split opposite legs and explicit
out_of_range derived values. See [posting contracts](../POSTING-AND-RETRIES.md).

The polished transaction entry/history/receipt experience reuses the starter's
fonts, semantic light/dark tokens and accessible primitives. It retains exact
amount strings, clear currency/status, empty/error recovery, durable receipt
references, confirmation/correction history and safe same-request retries.
No bank transfer, remote receipt fetch, FX conversion or later feature exists.

## Observed gates

- Final actual `./bin/verify --all`: exit 0, 223 tests/1,280 assertions, no failures/errors/skips, 655/699 covered application statements; wall 107.43 seconds.
- New PostingServiceTest: 46 cases/331 assertions. PostingConcurrencyTest: 13 cases/187 assertions. All 164 prior cases retained; account expectations now reflect a real complete empty ledger and existing-but-unused journal tables.
- Full gate passed PHP8.5-only platform/locks, zero advisory failures, guarded PostgreSQL migration/rollback/remigration, frontend lint/types/build, Pint, PHPStan, required suites/reports and all 16 Node harness/display checks.
- PostingService covered 155/163 statements; PostedAccountBalances 13/14; OpeningRequestSnapshot 2/2. Future M5 multi-service calculation coverage/release gates are not claimed.
- Real PostgreSQL concurrency observed two independent backend PIDs waiting together before releasing the owner lock: same-key replay, changed-payload conflict, one opening consumption and one reversal. Separate archive row-lock race rechecked the committed archived state before refusing a new movement.
- Real outer commits verify deferred unbalanced/pending-leg rejection, after-outer-commit events and callback discard on rollback. Three injected write checkpoints and failed correction prove complete rollback. A post-commit listener failure preserves the committed journal and same-key replay without duplicate movement.
- Private canonical F02 original transfer and F03 failure/retry probes passed; public test examples use sanitized synthetic values. F03 actual concurrency is evidenced by the separate-connection tests. F14 direct owner isolation passed, including known-key opening/reversal/correction requests over actual authenticated HTTP.
- Upgrade from the accepted schema with existing account/immutable opening rows, rollback and remigration preserved their values/timestamps and created no journal/link automatically. Probe fixtures were removed by guarded disposable-schema reset.
- Fresh corrected-source Pest TIA record: all223 tests/1,280 assertions, wall89.84 seconds. Unchanged replay:223 explicitly cached results, wall5.89 seconds/native2.51 seconds. Replay is not fresh test execution. Earlier cycle3 native planner simulation selected both posting test files after a simulated prior PostingService hash; no application/cache mutation or actual impacted execution is claimed.
- Independent actual Chromium review:28 assertions/22 inspected screenshots, desktop/tablet/390px/360px, light/dark, short dialog and enlarged equivalent reflow. Keyboard entry, validation/focus, exact maximum amounts, receipt/history, Cancel/Escape/focus return, reversal/replacement, repeated submission, lost transaction/opening success and direct owner denials passed. Zero page errors/unexpected request failures; two deliberate response losses. Server stopped and synthetic database reset. See [visual review](M1-P2-visual-review.md).

## Bounded corrections

Cycle1 exposed a migration lifecycle collision: migrate:fresh removes tables but
retains PostgreSQL functions. Owned function declarations were corrected for
fresh resets. The next targeted run passed91 cases; a commit-time constraint
exception assertion was corrected to match actual PDO commit behavior.

Cycle2 independently found the opening-key success gap and hidden destination
error; year-zero validation was also reproduced. Actual red/green regressions
and corrected source review passed. The targeted96-case run had741 assertions.

Cycle3 fixed actual rendered focus timing, accessible submit names and clipped
long correction text. All four observed UX findings were rechecked GREEN;
that cycle's full gate passed217/1,102 and653/696. See [independent review](M1-P2-independent-review.md).

Cycle4 addressed a P2 found by hosted review after the initial PR CI passed.
Account responses previously read balance and opening status separately. Four
actual separate-connection commits reproduced contradictory detail/list
payloads, for both opening posting and reversal. One PostgreSQL statement now
returns intent amount, journal identity/status and the complete balance together;
serialization uses that immutable snapshot without a second status query. All
four regressions passed124 assertions; two zero/out-of-range metadata cases
also passed. The full targeted run passed102 tests/919 assertions, followed by
the final223-case uncached gate and refreshed TIA above. The reviewed Vue files
and public payload shape are unchanged; the UX reviewer confirmed prior browser
evidence remains applicable without claiming a new rendered backend run.

## Limits and remaining publication gates

In-process after-commit events are best effort, not a durable outbox or an
exactly-once delivery guarantee; downstream readers must reconcile by journal
identity. Future cutoff/FX/reservation/integration behavior is unimplemented.
Native browser-chrome zoom, screen-reader/cross-browser and full WCAG
certification were not performed; enlarged reflow scope is stated in the UX
report. No production users, live credentials, external financial actions,
main/stage promotion or deployment occurred. Native Goal Mode is not activated
or claimed.

The initial sanitized draft [PR #4](https://github.com/hussain4real/halal-wealth-os/pull/4)
and its original217-case CI passed publication checks before the hosted finding.
The corrected source still requires final inventory/HEAD confirmation, follow-up
CI/review, normal exact-head dev merge and original post-merge CI. Their exact identities/results are retained privately and in the
PR; no future success is inferred here. Phase acceptance remains human-only.
