# Private V1 environment reference

The implemented environment is an isolated synthetic rehearsal on native PostgreSQL
18.4 and the pinned CI PostgreSQL 18.6 service, PHP 8.5.12+ within 8.5.x, and the locked Node application build. Local and
CI databases are disposable. No production host, backup destination, mail
account or real owner data has been selected or configured by this phase.

| Setting                                                          | Private release requirement                                                                                           |
| ---------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `APP_ENV`, `APP_DEBUG`                                           | `production`, `false`                                                                                                 |
| `APP_URL`                                                        | Owner-approved HTTPS origin; no credentials in the URL                                                                |
| `APP_KEY`                                                        | Valid Laravel key, separately escrowed; never rotate or regenerate during restore                                     |
| `DB_CONNECTION`, host/port/database/user/password                | PostgreSQL 18; private network; deployment-specific least privilege; no connection URL override                       |
| `SESSION_DRIVER`                                                 | Persistent `database`, `file` or configured `redis`                                                                   |
| `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, HTTP-only, same-site | Secure and encrypted, HTTP-only, `lax` or `strict`                                                                    |
| Mail transport                                                   | Owner-selected authenticated delivery; password reset delivery must be rehearsed; `array`/`log` are rehearsal only    |
| Registration                                                     | Disabled; initial owner uses the existing explicit private onboarding command                                         |
| Two-factor policy                                                | Confirmed enrollment and password confirmation; production data routes require enrollment                             |
| Admin access                                                     | Explicit `is_admin` capability plus ordinary owner-scoped queries                                                     |
| Backup key                                                       | Independent existing 32-byte secret; separate from archives and APP_KEY; no value on command lines or in CI artifacts |
| Logs, sessions, uploads                                          | Private storage and explicit retention; never publish raw evidence                                                    |

`php artisan release:check` returns boolean configuration/schema checks only.
It exits nonzero on a failed check and always reports `deployment_authorized:
false`. A passing report does not prove TLS termination, SMTP delivery,
least-privilege grants, durable storage or offsite retention. These require
inspection of the selected deployment. The shared platform verifier separately
enforces the PHP security floor and required extensions, including sodium.

`GET /up` is process liveness. `GET /ready` additionally checks a real database
query, exact applied migration IDs and the reviewed export table/column catalog.
It returns only `ready` or `unavailable`; use network-level monitoring limits.
It does not expose configuration, owner records or exception traces.
