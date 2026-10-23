# M6-P2 provider selection

The human owner approved Twelve Data for both raw daily equity prices and direct
reporting FX on 23 October 2026, for mock testing only. The earlier Alpha Vantage
and ECB proposal was declined. This record resolves provider selection for the
disabled adapters; it grants no live access or data entitlement.

The implementation accepts explicit listing symbol, exchange MIC, quote currency
and exchange timezone. Daily prices request unadjusted bars and retain their
source calendar date without inventing a publication instant. Direct FX retains
the supplied source timestamp. Both endpoints share one bounded request budget;
all current verification uses synthetic responses and prevents unmatched HTTP.

Live connectivity is **BLOCKED**. No account, credential, purchase or enablement
is authorized. Before any live use, the owner must approve access and the vendor
must establish the required display, market coverage and financial-record
retention rights. The default termination/deletion provisions do not establish
permission for indefinite immutable retention. A future access decision must
resolve that conflict without deleting retained financial history.

The [provider access record](../PROVIDER-ACCESS.md) retains the official sources,
coverage limitations, weighted credits, retry policy and outstanding licensing
conditions. Mocked tests and local synthetic recovery do not verify live
connectivity, vendor entitlement, production operation or phase acceptance.
