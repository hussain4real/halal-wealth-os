# Private identity and application shell

M0-P3 implements a private Inertia application and a separate restricted
Filament administration surface. Public GET/POST /register routes are absent;
the bundled registration view/action source is retained for a separately
approved future decision. There is no environment switch that enables signup.
Login, logout, reset, email verification, passkeys, password confirmation and
confirmed two-factor authentication remain in Fortify.

## Onboarding and capability

The first owner is onboarded only through the interactive `php artisan
owner:onboard` command against an explicitly selected application database.
It refuses noninteractive invocation and any nonempty user store. Name/email
and password confirmation use the existing validated creator; passwords are
hidden prompts, never CLI arguments or output. PostgreSQL transaction advisory
locking serializes simultaneous first-owner commands. The command asks for
explicit admin-capability confirmation, then issues email verification through
the configured mail transport. No owner has been provisioned by this phase;
automated evidence uses synthetic users in the owned disposable test database.
Do not invoke onboarding against production as part of tests or deployment.

`is_admin` defaults false and is not mass assignable. Profile requests reject
id, owner_id and is_admin. There is no web capability-management endpoint or
admin user creation/edit action. Any later capability administration needs a
separate authorized workflow. A verified owner with the explicit capability
may access Filament through the same web guard and Fortify session; no second
login, registration, password reset or public admin frontend is introduced.
Every admin request checks the capability; Livewire authentication middleware
is persistent. Local environments have the same capability restriction.

## Ownership conventions

Derive the acting owner from authenticated server state. Apply the explicit
owner query scope before looking up a caller-supplied record ID, then enforce
the policy before reading or changing it. The current identity record is the
User itself, so User::ownedBy selects that user's key. Future owned models must
scope their owner_id from the actor and implement their own policy; do not
copy this identity-key scope into financial tables. Never trust a submitted
owner ID, hidden input, navigation state or cached frontend capability.

The /owners/{owner} profile alias exercises ID-based access; the existing
/settings/profile flow continues to use the actor directly. UserPolicy permits
self access only. Filament OwnerResource also scopes its query to the actor,
including direct URLs and Livewire record resolution. Admin capability grants
panel entry, not access to a second owner's records. The first resource is a
read-only owner identity view: no financial model or behavior is implemented.

## Audit conventions and sensitive values

SecurityEvent is a fixed event vocabulary; SecurityAudit accepts only numeric
actor/subject IDs in its structured output. Extra context is discarded rather
than recursively trusting caller keys. Never pass request bodies, passwords,
reset tokens, passkeys, recovery codes, session identifiers, names/emails or
financial values to logs. Authentication success/failure/logout/reset events
and profile updates are attributed where a server-authenticated actor exists;
anonymous/console events have a null actor. Initial onboarding records a
numeric subject separately. Failed attempts have a null actor and a numeric target subject when known;
they do not authenticate the attempted user. Authentication failures do not persist attempted
email addresses or credential arrays.

The local daily security channel uses storage/logs/security-*.log and a 14-day
retention default; it is excluded from source publication. This is an event
convention, not an immutable/compliance audit store. Before personal production
use, review access, retention and error-reporting destinations separately.
Filament avatars use a local asset, never an owner name sent to a third party.

## Sessions and personal production readiness

The starter defaults to database sessions (120-minute idle lifetime), HttpOnly
cookies, SameSite=Lax, a host-only cookie and optional secure-cookie/encrypted
session settings. Test requests use an isolated array session; browser smoke
uses local file sessions. Login regenerates the session, logout invalidates it
and regenerates the CSRF token. Login is limited to five attempts/minute per
normalized identity/IP; password recovery is limited to five requests/minute
per IP across request/reset endpoints. Two-factor and passkey rate limits and
password confirmation remain enabled. Production uses Laravel's stronger
password rules; no real credentials or environment security settings were
created or changed here.

Personal production use is blocked until HTTPS and secure cookies, a unique
private application key, private session storage, non-debug error handling,
approved mail/error-log transports, session timeout policy, backups and owner
recovery procedures have been explicitly checked in that environment. Confirm
two-factor enrollment and safely retain recovery codes before personal
production use. In production, dashboard entry redirects an unenrolled owner
to Security; panel access requires confirmed two-factor authentication as well
as verified email and capability. Security/profile enrollment paths remain
available. Passkeys remain an additional retained feature, not a substitute
for this two-factor requirement. This local phase does not authorize or claim
a production readiness check or deployment.

## Presentation boundaries

The shell includes keyboard navigation, a skip-to-content link, labeled profile
and security navigation, empty states, and live validation errors linked to
core login/profile inputs. CurrencyDisplay presents exact caller-supplied
decimal text and explicit currency without Number conversion, rounding,
exchange, aggregation or an assumed currency. Missing/malformed data is
unavailable, never a fabricated zero. It is presentation only; no financial
calculations or records are implemented.

## Locked administration dependency

Filament 5.10.2 and Livewire 4.4.7 were verified against official release metadata
and resolved with the retained PHP ~8.5.0 / Laravel13 starter. Only the new
accepted-stack dependency graph was added; existing locks/ranges stay retained.
[Installation](https://filamentphp.com/docs/5.x/introduction/installation) and
[authorization](https://filamentphp.com/docs/5.x/users/overview) document the
panel and FilamentUser contract. Verification publishes generated Filament
assets from the locked vendor package; generated public/css, js and fonts
under filament are ignored. Run `php artisan filament:assets` after locked
installation/package discovery for local startup. No application scaffold was
regenerated or pruned.
