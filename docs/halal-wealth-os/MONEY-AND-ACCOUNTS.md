# Exact money and account contracts

Status: M1-P1 is human accepted. The separately authorized M1-P2 integration
is under review. Accepted ADR-001/002/003 directions and the original Money
contract are retained. See [posting and retries](POSTING-AND-RETRIES.md). FX
conversion remains outside the implemented scope.

## Exact representations

Cash uses signed PostgreSQL BIGINT minor units, inclusive range
`-9223372036854775808` through `9223372036854775807`. PHP and JSON expose these
as strings. Money requires an explicit supported Currency and accepts no PHP
float/int or JSON numeric amount. Cash input has at most its currency exponent
fractional digits; extra digits, including zeroes, are rejected before saving.
There is no rounding in input parsing, arithmetic or persistence.

The intentionally bounded supported catalogue is QAR/NGN/USD/EUR/GBP (exponent
2), JPY (0) and KWD (3). It is not a claim of complete currency support. Codes
are uppercase and must be listed; there is no default or guessed currency.
Changing an account currency or type after creation is refused.

Quantities, prices and rate representations use ExactDecimal strings with
NUMERIC(30,12) bounds: at most 18 integer and 12 fractional digits. The maximum
magnitude is `999999999999999999.999999999999`. Lexical excess scale, overflow,
scientific notation, separators, plus signs and non-string numeric inputs are
rejected. Canonical output removes insignificant trailing fractional zeroes
and negative zero. Decimal addition/multiplication compute exactly and reject
an unrepresentable result; no PostgreSQL numeric cast is used to trim inputs.
The representation is signed; future quantity/price/FX use cases must impose
their own semantic sign/positivity rules. No FX observation or conversion is
created by representing an exact decimal.

Only the explicitly named cash `Money::settle` operation rounds an exact
decimal: HALF_UP at the currency exponent, with ties away from zero, including
negative amounts. For example, QAR 1.005 becomes 1.01 and -1.005 becomes -1.01.
The result still must fit the cash range. Source-authoritative settlements
must be supplied as exact cash; this helper does not overwrite them. Remainder
allocation, conversions, portfolio cost basis and final disposals remain later
phase responsibilities. The installed required BCMath extension performs
string arithmetic; no dependency/lock change or JavaScript Number arithmetic
is used. Both primitives reject out-of-range outputs rather than wrapping.

## Derived values and signs

Bank, cash and investment accounts represent assets; liability accounts
represent debt/credit positions. A balance uses signed debit-minus-credit
movements: positive assets/overpayments increase net worth; negative debt and
asset overdrafts reduce it. A liability owed 100 with a repayment of 30 is
-70; an overpayment resulting in a credit of 10 is +10. Never apply `abs()` to
liabilities or flip a credit into debt. Balances stay in native currency.
Money addition/subtraction and derived movement input reject mixed currency;
this phase has no conversion escape hatch.

Internal clearing, equity, income and expense accounts are ledger bookkeeping
accounts and do not contribute to displayed net worth. They remain owner
scoped. Clearing never inflates displayed wealth. No cross-currency net-worth
aggregate is implemented or implied.

AccountBalanceReader remains a read-only port. PostedAccountBalances reads
opening completeness and the exact posted sum in one PostgreSQL statement.
Both posted originals and originals labelled reversed contribute alongside
their linked reversal legs. The full sum is checked only after cancellation.
A genuinely complete empty ledger derives zero. An unconsumed opening request
returns null/unavailable with opening_pending; unknown is never zero. A total
outside the Money range returns null/unavailable with out_of_range, without
truncation. Internal accounts stay excluded from net worth even at that edge.

## Opening input and lifecycle boundary

Optional manual opening input still goes through OpeningBalanceGateway.
DeferredOpeningBalances saves one immutable intent atomically with account
metadata; creation performs no journal posting. Its currency/account pair is
constrained in PostgreSQL. An explicit confirmation calls the M1-P2 service,
which posts the signed opening against internal equity and saves one unique
intent-to-journal link in the same transaction. No migration consumes old
requests. The original amount and timestamps stay unchanged. The UI reports
awaiting_posting, posted or reversed from actual linked history. Reversal does
not permit silently consuming the same intent again.

There are no editable balances. M1-P2 supplies the balanced journal and
append-only reversal/replacement history. M1-P3 FX, observation provenance and
reconciliation remain unimplemented. A complete account with no opening intent
can now derive zero from its real empty journal, instead of the historical
M1-P1 absent-source response.

Every account query/write is owner scoped and policy checked, including direct
URLs and admin users. Account routes require authentication, verified email
and confirmed production 2FA. Creation assigns the authenticated owner server
side. Requests reject unknown/authority/balance fields. Only a name can be
changed; owner/currency/type are immutable. Archiving preserves readable rows
and requests and refuses further metadata/opening changes. No account DELETE
route exists; the model refuses deletion. Owner deletion is refused when any
active or archived account exists, with a PostgreSQL restrictive FK as a second
boundary. No financial history cascades away through profile deletion. Posting and journal foreign keys also restrict deletion. Service-created
internal accounts cannot be renamed or archived through the account UI.

All financial strings remain strings through persistence, Inertia props and
Vue display. UI inputs use text, never numeric coercion. The app distinguishes an unposted request from a complete posted balance.
The explicit posting action leads to its durable journal receipt. It contains only synthetic test
fixtures; no real owner/account or credential is provisioned.
