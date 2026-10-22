# M6-P1 independent review

The independent source reviewer inspected the canonical phase, implementation,
financial service boundaries, identity/retry/correction paths, owner isolation,
export/recovery changes and the complete public diff. The final application
review binds commit `ab374812b7cc77cac9e04719db786e844af2f03e`; the strict
recovery-reader delta was re-reviewed at `598d0f7d2f6a0dd738e3e7c355ddd1c52eee2a01`.

All four source findings are resolved: correction cannot bypass current ambiguity;
conflicting provider facts are flagged before partial approval; retained replay
cannot introduce inferred fallback aliases; and repeated rows in an overlapping
statement cannot collapse through one old fingerprint. Each correction has
observed failing-before and passing behavioral evidence retained privately.

The source verdict is READY_FOR_REVIEW with no remaining demonstrated blocking
finding or sensitive-publication finding. The reviewer did not execute runtime
checks, browser journeys or GitHub actions. Full private reports and exact hash
inventories remain outside the public repository. This source verdict does not
replace full CI, fresh hosted review, owner acceptance or deployment authority.

The four-file selector polish was independently source-reviewed at
`0ab4ae8816867e68ba75beb342ed4e1cad8f5fcc`: both joined relations retain explicit
owner filters, the shared DTO matches its consumers, labels are escaped and the
selected-preview link remains relative and authenticated.

## Rendered review

A separate reviewer used an isolated synthetic database/server and real browser.
The executed journey covered bounded CSV upload, mapping, private dry-run errors,
explicit repeated-row and transfer resolution, selected batch approval, committed
response loss followed by locked unchanged retry and exact receipt recovery,
reupload, actual formula-safe CSV download, separately previewed linked replacement,
source/original/financial receipt navigation, loading and accessible error focus.
Desktop and 390px mobile layouts, light/dark states and keyboard focus were inspected.
Saved screenshots were individually viewed; raw screenshots and exact data remain
private. Two CUA upload calls stalled at the tool layer; the reviewer completed
the journey with the terminal Playwright workflow. Rejected/stale captures are
not counted as passing evidence.

The initial low usability finding was an opaque replacement-preview selector.
The final source/currency/preview-number label and explicit selected-preview link
were rechecked against the exact polished application files and a fresh build.
The actual new tab opened the correct owned preview/source row; mobile layout
remained within its viewport. The finding is resolved. Owned browser/server/database
resources and runtime credentials were cleaned up separately.

Verdict: READY_FOR_REVIEW, with no remaining demonstrated blocking source,
publication or rendered finding. The full local release proof at `598d0f7` and
subsequent narrow affected checks retain their distinct source identities in
[the phase run](M6-P1-local.md). Final exact-head hosted full CI/fresh review,
original dev CI and signed public publishing remain required. This review does
not accept M6-P1 or authorize a production deployment.
