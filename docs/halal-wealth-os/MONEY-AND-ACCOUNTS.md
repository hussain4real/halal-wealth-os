# M1-P1 money and account contracts

Status: local implementation decisions for M1-P1 review, following accepted
ADR-001/002/003 directions. M1-P1 is not human accepted. No posting or FX
conversion service is implemented by this phase.

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

AccountBalanceReader is a read-only port. BalanceSnapshot derives a cash value
from a complete iterable of signed movements, computing a full exact sum
before checking the final cash range. Current application binding is
UnavailableAccountBalances: the real posting source does not exist, so the
response carries `balance: null`, `balance_status: unavailable` and no net-worth
contribution. Unknown is not zero. A complete empty source may derive zero;
an absent source may not. The pure derivation and injected-reader tests are
synthetic contract evidence, not actual posted-history integration.

## Opening input and lifecycle boundary

Optional manual opening input goes through OpeningBalanceGateway. Its current
DeferredOpeningBalances adapter saves one immutable opening request, in integer
minor units, atomically with the account metadata. The account/request currency
pair is constrained in PostgreSQL. The request is explicitly `awaiting_posting`;
it does not create a journal/transaction, affect balances or wealth, schedule
automatic posting, or claim a successful financial operation. It is retained
for an explicitly authorized future posting-service integration. Missing input
creates no request and does not silently set a zero balance.

There are no editable balance fields, posting endpoints, posting tables,
idempotency/reversal logic or FX records. M1-P2 owns the actual balanced journal,
opening posting and history/reversal constraints. M1-P3 owns FX provenance and
conversion. This phase cannot demonstrate retaining real posted history that
does not exist; it proves archive-only account retention and preservation of
current metadata/unposted requests, and establishes the no-delete boundary.

Every account query/write is owner scoped and policy checked, including direct
URLs and admin users. Account routes require authentication, verified email
and confirmed production 2FA. Creation assigns the authenticated owner server
side. Requests reject unknown/authority/balance fields. Only a name can be
changed; owner/currency/type are immutable. Archiving preserves readable rows
and requests and refuses further metadata/opening changes. No account DELETE
route exists; the model refuses deletion. Owner deletion is refused when any
active or archived account exists, with a PostgreSQL restrictive FK as a second
boundary. No financial history cascades away through profile deletion. Future
posting foreign keys must also restrict deletion; that future schema is not
claimed here.

All financial strings remain strings through persistence, Inertia props and
Vue display. UI inputs use text, never numeric coercion. The app distinguishes
an unposted request from an unavailable posted balance and does not offer
editing/confirmation that implies posting. It contains only synthetic test
fixtures; no real owner/account or credential is provisioned.
