# M6-P4 rendered and recovery review

The coordinator exercised the actual built UI against an owned disposable PostgreSQL database and a private synthetic Google HTTP harness. An independent source-read-only reviewer inspected settled desktop/mobile light/dark screenshots and retained states. No real Google account, consent, credential or upload was used. Raw screenshots, identities, paths, request selectors and logs stay private.

Observed checks:

- Rejecting the displayed school-account mock created no folder or report. A separate mock callback showed the returned personal identity, with account acknowledgement reset.
- Explicit folder acknowledgement queued one private-folder intent; the worker verified that folder without uploading a report.
- The report review displayed actual account/folder, captured/saved evidence, byte length, content hash and manifest. Content acknowledgement started unchecked. Changing the report removed the previous review and consent. A delayed review disabled selection until the response settled.
- A response dropped after confirmation committed left the request locked. Hard reload recovered its exact receipt without a duplicate admission. A subsequent deliberate confirmation in the same instance received a distinct request identity.
- An abort before server write remained locked across disconnect/reload. Exact retry returned a definitive no-write result and cleared the draft; current failure guidance remained visible.
- Two deliberately admitted reports produced two distinct remote identities. The coordinator recomputed each fake remote file's SHA-256 and matched its retained confirmation. Total creates were one folder and two reports.
- The foreign owner saw no connection, saved report or operation. Actual foreign receipt/report requests returned 404. Keyboard Tab reached the existing skip-to-content control. The tested mobile layout had no document overflow and one main landmark.
- Desktop and mobile light/dark views kept account, destination, manifest, checked-state, error and history readable. Folder-only and synthetic report completion are distinguished; disconnect preserves historical results.

Expected browser console entries were retained for the two deliberately aborted confirmation requests, a missing interrupted receipt, a definitive 422 rejection and foreign-owner 404s. No error-free-console or accessibility-certification claim is made. The independent reviewer inspected pixels/states; it did not independently run the browser, database or byte checks.

These are local synthetic UI/recovery results. Full uncached verification, original exact-head CI, fresh hosted review, merge, original post-merge CI, signed public progress publication and owner acceptance remain separate gates.
