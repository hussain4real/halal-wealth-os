# M0-P2 approved Vite Plus patch and blocker clarification

The human approved Vite Plus 0.3.0 -> 0.3.3 through the manager. This is a bounded
tooling update, not phase acceptance, runtime-retry authority or remote publication.
The original full-starter source identity and prior evidence are retained. M0-P2
and the sole implementation owner's claim remain BLOCKED.

## Audit identity and exact affected versions

The original npm audit count of three critical findings represented three affected
package entries in one dependency chain, not three independent vulnerabilities.
There is one vulnerable package, tinypool, with two distinct advisory records:

| Advisory                              | Affected tinypool versions | First patched version |
| ------------------------------------- | -------------------------- | --------------------- |
| GHSA-5gmw-xhrv-c9v3 / CVE-2026-104848 | <=2.1.0                    | 2.1.1                 |
| GHSA-85c8-ppgw-ccpr / CVE-2026-104849 | <2.1.2                     | 2.1.2                 |

The installed path was devDependency vite-plus 0.3.0 -> oxfmt 0.64.0 -> tinypool
2.1.0, at node_modules/vite-plus, node_modules/oxfmt and node_modules/tinypool.
The aggregate audit affected ranges were:

- tinypool: <=2.1.1 (the union of the two advisory ranges).
- oxfmt: 0.18.0 - 0.66.0, affected through its dependency, not a separate advisory.
- vite-plus: <=0.0.0-gd42e0ca6.20260225-0619 || 0.0.2-g17a37daf.20260304-1136 - 0.3.1,
  propagated from the formatter dependency.

Publisher metadata confirms oxfmt 0.67.0 and 0.68.0 pin tinypool 2.1.2. Vite Plus
0.3.2 already selected oxfmt 0.67.0; the approved 0.3.3 selects oxfmt 0.68.0.
No claim is made that 0.3.3 is the first release fixing these advisories.

Both issues consume prototype pollution supplied by another vulnerability; they
do not independently create that pollution. They can redirect worker startup or
worker-module selection and execute code with the development/CI process's
privileges. This is a development-tool dependency. No reachable application
exploit, malicious input, production exposure or production import was established.

References: [worker-options advisory](https://github.com/tinylibs/tinypool/security/advisories/GHSA-5gmw-xhrv-c9v3),
[run-options advisory](https://github.com/tinylibs/tinypool/security/advisories/GHSA-85c8-ppgw-ccpr),
[official Vite Plus release](https://github.com/voidzero-dev/vite-plus/releases/tag/v0.3.3),
[Vite Plus manifest](https://registry.npmjs.org/vite-plus/0.3.3),
[oxfmt manifest](https://registry.npmjs.org/oxfmt/0.68.0).

## Applied scope and observed checks

The official release and npm publisher metadata identify VoidZero's vite-plus
package and repository. The published chain is 0.3.3 -> oxfmt 0.68.0 -> tinypool
2.1.2. The Node engine remains ^20.19.0 || ^22.18.0 || >=24.11.0; the existing
24.19.0 runtime is retained. Metadata was checked before installation.

An initial single-pin update cleared audit but its build reported: Expected
@voidzero-dev/vite-plus-core@0.3.3, but found vite@8.3.4. The exact installed 0.3.3
upgrade/local-CLI guides require aligning the Vite alias and npm overrides.
Applied only these related direct-manifest changes:

- vite-plus: 0.3.0 -> 0.3.3.
- vite: ^8.0.0 -> npm:@voidzero-dev/vite-plus-core@0.3.3.
- npm overrides: matching exact Vite alias and bundled vitest 4.1.11.

Other direct package pins/ranges, optional declarations, scripts and starter
features remain. No full migrator, force flag, unrelated direct upgrade or feature
removal was used. The newer formatter moved one semicolon around an existing
Chisel marker in Security.vue; passkeys and two-factor behavior remain present.

| Command                                                                   | Observed result                                             |
| ------------------------------------------------------------------------- | ----------------------------------------------------------- |
| npm install --ignore-scripts --no-audit --no-fund with allowed task cache | 0; lock regenerated through permitted npm registry          |
| npm ci --ignore-scripts --no-audit --no-fund                              | 0; 240 packages installed from the patched lock             |
| npm audit --json                                                          | 0; info/low/moderate/high/critical/total all zero           |
| npm ls --all                                                              | 0 after clean locked install                                |
| npm run check                                                             | 0 after the required single-file formatter adjustment       |
| npm run types:check                                                       | 2; missing generated Wayfinder routes/actions               |
| npm run build                                                             | 1; matching core resolves, then Wayfinder cannot invoke PHP |

Zero audit findings means the current registry advisory dataset reports none for
this lock; it is not a blanket security or application compatibility guarantee.
No Composer lock, PHP application suite, migration smoke, coverage or hosted CI
success is claimed.

## Exact environment failures and supported next action

Existing agent-side requests failed before reaching their HTTPS services with
urllib.error.URLError: Tunnel connection failed: 403 Forbidden:

| Host            | Previously attempted request                                                                                           |
| --------------- | ---------------------------------------------------------------------------------------------------------------------- |
| getcomposer.org | GET /versions; HEAD /download/2.10.3/composer.phar                                                                     |
| api.github.com  | GET repository/commit/release metadata; HEAD /repos/laravel/framework/zipball/685f8d6c2ab4547287500bc80ccdf2ca12635109 |
| pecl.php.net    | GET /rest/r/xdebug/allreleases.xml                                                                                     |
| www.php.net     | GET /downloads.php?json&version=8.5; not needed to obtain the already-downloaded PHP image                             |
| fonts.bunny.net | HEAD /css?family=instrument-sans:400,500,600; GET /css2?family=Instrument+Sans:wght@400;500;600&display=swap           |

The selected future coverage source pecl.php.net/get/xdebug-3.5.3.tgz has not been
retried. Normal GitHub archive redirects to codeload.github.com were permitted;
Composer's API dist entry point still needs explicit allowed-host access.
No exact font-file URL was observed because its CSS request was denied first.

Separately, Docker BuildKit's PHP-image RUN apt-get process failed against:

- https://deb.debian.org/debian/dists/trixie/InRelease
- https://deb.debian.org/debian/dists/trixie-updates/InRelease
- https://deb.debian.org/debian-security/dists/trixie-security/InRelease

Its first error was Temporary failure resolving 'proxy'. Mapping that same
configured proxy's host-resolved address addressed that DNS failure. The next
errors were SSL connection failed: error:0A000086:SSL routines::certificate verify
failed; APT exited 100. Supplying the existing environment CA bundle still failed.
These are distinct DNS, TLS-trust and destination-policy error classes.

The host has a readable platform-provided public certificate at
/usr/local/share/ca-certificates/environment-proxy-ca.crt and a CA bundle at
/etc/ssl/certs/ca-certificates.crt. Their presence is verified; matching the public
CA to the proxy and effective nested-build APT trust has not been proven. The
Dockerfile's final public-CA-secret readability adjustment is untested. No TLS
verification was disabled and no further build was attempted.

Current official cloud documentation provides environment Internet access /
Additional allowed domains, saved Install script and Start skill setup, then
Republish and a new task. Other subdomains need explicit entries; api.github.com
is distinct from github.com. An authorized owner/admin can address policy denials
for the exact required hosts, subject to workspace requirements. These settings
do not initialize nested Docker DNS or certificate trust and are not retroactive
to this running task. There is no verified documented nested-Docker certificate
bootstrap recipe in the consulted environment guide.

The smallest support handoff is to provide the errors above and ask for supported
proxy DNS and public CA provisioning/lookup for the Docker BuildKit APT process.
A limited read-only diagnostic of the effective CA path, certificate identity and
APT-worker readability should precede any approved retry. No private key,
application credential, Mac certificate or account token is needed for that
handoff. No proxy, network, security or certificate configuration was changed in
this follow-up. Do not reset this task: preserve/transfer the uncommitted source
through a supported mechanism before any separately approved replacement task.

[Official cloud environment configuration](https://learn.chatgpt.com/docs/environments/cloud-environments)

## Review status and outstanding code findings

No separate independent reviewer or security scan ran. The implementation owner
performed source/diff self-review and command-evidence checks. Independent review
remains outstanding. Two source findings remain unchanged by this bounded patch:

1. scripts/check-planning.mjs asserts only M0-P1 may be accepted and every milestone
   stays PLANNED. Legitimate later human acceptance would therefore fail the
   harness. Replace that lifecycle coupling with validated human acceptance and
   dependency-history rules before accepting the next phase.
2. bin/dev-runtime starts PHP in an offline PHP-only container; the image does not
   provide Node/npm/Composer. That is sufficient for isolated PHP checks after
   completion, but the README's full composer dev workflow needs a cohesive
   supported runtime. It has not been demonstrated.

Both are ordinary bootstrap correctness/completeness findings, not additional
security advisories. Runtime/application validation gaps remain as in the original
blocked run. The parent must arrange independent review before phase readiness.

## One-time recheck after the user reported all domains allowed

At 2026-10-17 12:30 UTC, the user reported enabling downloads for all domains and
explicitly requested another check. Each of the five official endpoint requests
listed above was repeated once through the configured proxy. All still failed
with URLError: Tunnel connection failed: 403 Forbidden. This is new observed task
behavior, not proof of the saved environment setting or its publication status.
No alternate host, direct-egress route or credential was used.

A separate read-only diagnostic used the existing official PHP image. PHP's
gethostbyname for the configured proxy reported unresolved. Curl nevertheless
reached a TLS exchange and returned errno 60, HTTP code 0: SSL certificate problem:
self-signed certificate in certificate chain. Repeating that diagnostic with a
process-local mapping to the same proxy address produced the same TLS error.
Peer and hostname verification stayed enabled. No CA was imported, proxy/network
security setting changed, Docker build retried or APT request executed. The
historical APT DNS and TLS failures remain distinct from this current curl result.

Supported next step: verify the saved environment Internet access configuration,
Additional allowed domains or All selection, Republish status and any workspace
policy. Official documentation says a new task is required to use environment
updates; existing tasks keep their own state. Current settings cannot be inspected
by these tools, and the results cannot distinguish an unpublished change, old-task
policy or an overriding workspace restriction. Do not reset or discard this task.
Preserve the uncommitted deliverables through an approved handoff before starting
one separately authorized replacement, with no overlapping implementation owner.

The nested-container certificate failure needs platform-supported public CA/trust
and proxy DNS setup. There is still no verified nested-Docker trust-provisioning
recipe for this environment. Provide support the exact error classes, affected
process and public certificate path; ask them to confirm the CA identity and the
APT worker's effective CA path/readability. No personal certificate/private key,
application secret or account token is needed. The domain setting alone does not
prove or repair nested image trust.
