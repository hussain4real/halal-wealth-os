# M6-P2 provider selection proposal

Status: proposed for owner approval on 23 October 2026. Neither provider is
approved, configured or enabled yet. Live connectivity is BLOCKED. This record
proposes adapter development with synthetic deterministic transports only.

## Price observations: Alpha Vantage daily equities

Use the official HTTPS query API for raw end-of-day equity closes. Resolve the
exact provider symbol and currency through SYMBOL_SEARCH before accepting a
TIME_SERIES_DAILY observation. Do not infer exchange, currency or coverage from
a bare ticker. Retain the exact owned mapping, provider response identity and
raw split basis needed by the existing price service. Unlisted or ambiguous
assets retain manual entry. No adjusted, intraday, premium or trading endpoints.

The [official API documentation](https://www.alphavantage.co/documentation/)
describes daily raw prices and symbol lookup. The [support page](https://www.alphavantage.co/support/)
currently lists a 25-request daily free allowance for most datasets. Charge every
attempt and symbol lookup against one shared provider budget; retries consume
allowance too. Never assume an open-source quota exception or universal coverage.

The [terms](https://www.alphavantage.co/terms_of_service/) grant personal,
noncommercial use subject to their conditions. Commercial and affiliated-user
restrictions require separate eligibility review before any live use. Developing
an adapter does not accept terms, create an account, establish an entitlement or
authorize distribution of vendor data. Keep all public fixtures synthetic.
No API key will be created or requested during this phase.

## FX observations: European Central Bank reference feed

Use the official daily XML reference feed with EUR as the explicit base. Accept
only direct EUR pairs supported both by the feed and the application's currency
types. Do not synthesize inverse or cross rates. QAR, NGN and KWD coverage is not
claimed; their manual fallback remains available. These informational references
must never initiate or price a recorded cash conversion.

The [official reference-rate page](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html)
reports publication around 16:00 CET on working days and discourages transaction
use. Publication date is explicit; do not fabricate an intraday observation time.
The [ECB copyright conditions](https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html)
permit reuse with accurate reproduction, attribution and disclosure of changes.
No public redistribution or logo use is proposed. Retain ECB attribution in
observation source references and exports. Use one daily feed fetch with bounded
retries rather than a request for every pair. No published service quota or
availability guarantee is assumed.

## Approval boundary and verification

Owner approval here selects these two adapters for disabled development and
synthetic CI only. Live use needs a separate destination/account decision,
verified licensing/coverage eligibility, any privately supplied credential,
explicit enablement and an observed smoke check. No provider receives owner
holdings, balances or identity during mocked verification. A passing mock is
reported as MOCKED_PASS, never LIVE_VERIFIED. Manual observations and previously
saved valuations remain available during provider failure or disabled operation.

Provider-specific implementation and M6-P2-AC5 approval evidence remain pending
the owner's selection. No implementation cycle has been consumed by this proposal.
