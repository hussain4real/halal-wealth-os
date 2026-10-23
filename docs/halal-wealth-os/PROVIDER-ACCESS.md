# M6-P2 provider selection proposal

Revision 2, owner approved for mock-only development on 23 October 2026. The owner declined the Alpha Vantage
and ECB selection. That earlier proposal remains in Git history and private
review evidence. Neither rejected provider will be implemented under that
proposal. Twelve Data is selected for mock-only development; no live provider is configured
or enabled. Live connectivity is BLOCKED. This replacement selects both prices
and FX, with
disabled adapters and synthetic deterministic verification only.

## Proposed provider and transport

Use fixed HTTPS endpoints at api.twelvedata.com. For prices, the
[official documentation](https://twelvedata.com/docs) describes time_series
metadata with symbol, currency, exchange, MIC and exchange timezone. Require
an exact owned instrument mapping and explicit unadjusted daily bars, preserving
local raw-unit and split history. The documented adjustment default is splits;
request none explicitly and reject any unresolved unit-basis interpretation.
Daily dates remain dates interpreted in the verified exchange calendar, not
invented publication instants. No intraday streaming or trading endpoint.

For reporting FX, use the documented exchange_rate endpoint's explicit pair
and Unix timestamp. Preserve the original decimal token or use a documented
text format; decoding a JSON numeric rate through a binary float is forbidden.
Accept supported direct fiat pairs only. Do not infer an inverse/cross rate,
cryptocurrency coverage, QAR/NGN/KWD availability or entitlement to any instrument.
Unsupported pairs and assets retain existing manual observation entry. Provider
observations never initiate or price a cash conversion.

## Access, licensing and retained evidence

The [individual plans](https://twelvedata.com/pricing) currently list Basic with
8 API credits per minute and 800 per day. Basic is listed for internal non-display
use; Grow lists internal display access. This proposal selects no paid plan and
authorizes no purchase. Do not treat a free account as permission to show live
quotes in the application.

The [personal-use guidance](https://support.twelvedata.com/en/articles/5332349-commercial-and-personal-usage)
describes development/testing use and restrictions on redistribution. The
[terms](https://twelvedata.com/terms) make data access, display, storage and third-
party rights conditional on the relevant subscription and agreements. The default termination and expiration terms require Data deletion, including
section16's30-day provision and narrow compliance/audit exception. This conflicts
with indefinite immutable source, saved-valuation, export and backup retention.
Live activation needs documented vendor permission or a verified applicable
exemption covering those artifacts, plus exact market/pair entitlement and
private UI display rights. Do not delete immutable financial evidence to satisfy
a vendor caching or termination restriction.
All public and test data remain independently synthetic.

## Quotas, outage and verification scope

The [credit rules](https://support.twelvedata.com/en/articles/5615854-credits)
weight calls by endpoint and instrument, reset minute allowances and reset the
Basic daily allowance at midnight UTC. The proposed disabled implementation
conservatively caps shared weighted usage at 8 credits per rolling minute and
800 per rolling 24 hours, or a lower approved allowance. Metadata, price, FX,
failed/uncertain sends and retries share one durable provider budget. Never
multiply allowance by owner, worker or endpoint. Retries remain finite and honor
bounded backoff and safe rate-limit responses.

Owner approval here selects Twelve Data for disabled development and synthetic
CI only. It does not accept vendor terms, create an account/key, activate live
access, authorize redistribution or establish any license entitlement. Live use
needs a separate access decision and actual smoke evidence. No holdings, balances
or identity are sent during mocks. Mocked checks and live connectivity remain
separately reported. Manual observations and saved valuations stay local and
available during outages or disabled operation.

The human owner approved this exact Twelve Data selection for mock-only
implementation on 23 October 2026. This is the M6-P2-AC5 provider-selection record;
actual phase checks remain pending. The rejected proposal and all prior evidence
are preserved. Selection preparation consumed no implementation cycle.
