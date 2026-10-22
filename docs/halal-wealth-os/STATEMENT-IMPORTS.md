# Statement import contract

M6-P1 uses bounded private source records, immutable interpretations and terminal approval/correction receipts. One coordinator owns identity, matching, atomicity and retries. The existing PostingService and InvestmentService remain the financial writers. The parser owns explicit versioned CSV mappings; the CSV encoder owns spreadsheet safety.

## Design decision

Two source-read-only candidates compared a relational row workflow with immutable snapshots. The independent judge preferred the five-table snapshot design (24/25 versus22/25); the implementation owner agreed. Source-row replay anchors independent of mapping and complete owned-root matching rules were grafted from the relational design. Nine workflow tables, mutable row statuses, file-only retained sources and one wrapper per scalar were rejected. All seats used the supported harness model; no model-family diversity is claimed.

The five relations retain reusable adapter configurations, source bytes, interpretation snapshots, terminal receipts and identity claims. Raw UTF8 CSV is stored base64 in owner-scoped database text, so existing database-only recovery and complete owner export retain its exact source. No generic file URL is used. Owner export advances coherently to version2; version1 exports remain historical artifacts and are not an application restore format.

## Interpretation and approval

Version1 explicitly maps columns, transaction types, currency, date format and decimal separator. Amounts are positive native decimal consideration/magnitudes; income/expense and transfer-in/out specify direction. Investment amounts are gross consideration with separate fees/tax; an optional signed cash amount must equal the actual net effect. Existing matches require explicit target and in/out direction. Complex IPO/split creation is unsupported in CSV-v1 and remains a row error; use the existing portfolio workflow.

Selected rows execute in effective-date then source-row order; funding must precede dependent investment spending. Preview validates structure and explicit references. Approval rechecks current eligibility and authoritative investment replay; a row rejection rolls back the complete chosen batch. No row catch-and-continue is allowed.

Every approval takes the existing owner lock before identity reads. Exact committed request recovery precedes current eligibility. Provider IDs use an explicit source/account namespace; otherwise documented canonical fingerprints are used. Equal no-ID rows require stable explicit occurrence labels or exact existing matches. Source-byte digest plus original row anchors prevent reinterpreting already-approved bytes into another operation. Transfer references identify one explicit full transfer across both legs. A retained replay may add a source anchor but cannot introduce a new inferred fingerprint/occurrence alias that suppresses a still-pending equal row. Existing-operation matches add evidence only and cannot acquire correction authority.

## Corrections and portability

Corrections target an import-created outcome. Cancellation reverses the complete domain root; replacement uses an independently previewed row and the existing root correction service. A unique immediate correction prevents history forks. Replacement becomes the next correctable outcome; cancellation closes the chain. Original source/claims remain retained and resolve corrected history on re-upload. Same-key correction recovery precedes latest-outcome or account checks.

CSV exports are normalized evidence views, not lossless reimport files. Every untrusted text cell is neutralized against spreadsheet formulas before CSV quoting; exact original bytes remain in complete owner JSON. All import routes are authenticated, verified, production2FA protected and owner-isolated, including admins. CSV evidence export uses private no-store/nosniff attachments. Raw source, validation input and financial details must not enter logs, global page props or redirect sessions.

The [phase run](runs/M6-P1-local.md) records actual validation; this design document is not a passing verification or owner acceptance claim. Provider connections, OCR/email, notifications, Drive, real imports and deployment remain outside this phase.
