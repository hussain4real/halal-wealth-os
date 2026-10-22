# M5-P3 independent rendered review

Outcome: PASS, no blocking application finding. An independent read-only
reviewer drove the real built application in a dedicated parent-owned synthetic
browser environment, separate from the disposable full-suite database. No app
source was changed. Private receipts bind the tested source and observations.

## Actual user flows

Production authentication policy redirected unenrolled dashboard/data access
to password-confirmed Security. The reviewer enabled and confirmed two-factor
using the visible setup flow. A subsequent password login reached the genuine
challenge; an unused recovery code authenticated, while a fresh login rejected
reuse and remained on the challenge. Another unused code resumed the protected
Data screen. UI-only re-enrollment of this disposable synthetic owner then
proved a separate fresh-counter TOTP login through Dashboard to Data. No hidden
secret extraction, middleware bypass or authentication-cache disabling was used.

Guest export redirected to login; a fresh sensitive export required password
confirmation; ordinary-owner admin access returned403. `/ready` returned only
its minimal ready status. The loopback HTTP/noSMTP profile deliberately fails
HTTPS/secure-cookie/mail deployment checks; this browser pass does not approve
production configuration or deployment.

A real password-confirmed browser download contained schema1,48 data sections
and48 completeness sections. Every direct owner row matched the profile owner;
the foreign synthetic owner's name and profile authentication fields were
absent. All48 section counts and SHA256 values matched the downloaded data.
The coordinator separately confirmed zero remaining server export spools. The
reviewer removed only the exact downloaded synthetic file after hash checking.

## Layout and accessibility

Desktop1440×1000 and mobile390×844 were inspected in both light/dark themes.
Each had exactly one h1 and one main, no horizontal overflow, readable private
file/restore disclosures, an intact settings menu and complete download control.
Skip-link Enter focused main-content; natural Tab from the selected Data menu
reached the actual download anchor. Five nonsecret Data screenshots were opened
and inspected; private screenshot hashes remain in the review receipt.

## Preserved harness limitations

An initial browser download-event wait and connector rebinding timed out before
any export request reached the app. Those remain harness failures, not an app
failure or a successful download claim. The supported download helper later
completed and supplied the actual file proof. A restricted Node cleanup attempt
failed; scoped owned-file cleanup then succeeded. Browser tabs were closed,
viewport state reset and volatile authentication variables cleared. Server and
database lifecycle remained with the coordinator.

This review supplements the native tests and full uncached proof. Hosted
latest-head review/CI, actual dev merge, original post-merge application CI,
signed progress submission and owner phase/milestone acceptance remain separate.
