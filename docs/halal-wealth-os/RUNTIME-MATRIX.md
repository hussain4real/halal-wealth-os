# Runtime inventory and proposed compatibility matrix

Observed and official metadata reviewed on 17 October 2026. No installs or service changes were performed. Proposed pins require owner approval; metadata compatibility is an inference from declared ranges, not an installed/resolved/tested environment.

## Observed local inventory

| Component | Observed | Status |
| --- | --- | --- |
| OS/CPU | macOS 27.0.1 / arm64 | Verified local host; cloud unverified |
| PHP | 8.5.6 | CLI verified; older patch than proposed 8.5.11 |
| PHP extensions | pdo_pgsql, pgsql, bcmath, intl, mbstring; PCOV 1.0.12 support enabled | Presence verified; no integration/coverage run |
| Composer | 2.10.0 | CLI verified; proposed patch differs |
| Node / npm | 22.7.0 / 10.9.0 | CLIs verified; Node is below Vite 8 minimum 22.12 within the 22 line |
| pnpm / Yarn | Corepack pnpm fails; Yarn refuses home pnpm project selection | Unavailable in checked context |
| PostgreSQL | Client 18.4; default socket and 127.0.0.1:5432 no response | Client verified; server/version unavailable |
| Docker / Colima | 29.8.0 / 0.10.3; default Docker socket absent | CLIs verified; default server unavailable |
| Redis | redis-cli 8.4.0 | Client only; server not tested |
| Application dependencies | No app manifests, lockfiles or packages | Not installed or resolved |

## Proposed exact pins

These are candidate direct versions for a separately authorized M0-P2. The [manifest](bootstrap-manifest.json) is PROPOSED_BLOCKED. No pin is accepted or installed.

| Component | Candidate | Evidence and rationale |
| --- | --- | --- |
| PHP | 8.5.11 | Current stable source; 8.5 active support through end 2027, security through end 2029 [PHP-RELEASE, PHP-SUPPORT] |
| Composer | 2.10.3 | Maintained 2.10 line; release listed upstream [COMPOSER] |
| Node / npm | 24.22.0 / 11.19.1 | LTS release and bundled npm; supersedes unsuitable local Node 22.7.0 [NODE, NODE-POLICY] |
| PostgreSQL server/client | 18.6 | Supported current minor, major support through November 2030; Laravel supports PostgreSQL 10+ [POSTGRES, LARAVEL-PG] |
| laravel/framework | 13.37.0 | PHP 8.3-8.5 supported; longer support runway than security-only Laravel 12 [LARAVEL-SUPPORT, LARAVEL-PIN, LARAVEL-REQ] |
| filament/filament / livewire/livewire | 5.10.2 / 4.4.7 | Filament support metadata permits Laravel 13 and requires Livewire ^4.4.7 [FILAMENT-PIN, FILAMENT-REQ, FILAMENT-METADATA] |
| inertiajs/inertia-laravel | 3.5.1 | Declares Laravel ^13 and PHP ^8.2 [INERTIA-PHP] |
| @inertiajs/vue3 / @inertiajs/core | 3.9.1 / 3.9.1 | Matching client release; Vue ^3 peer range [INERTIA-VUE] |
| Vue | 3.5.44 | Stable release satisfying Inertia peer range [VUE, INERTIA-VUE] |
| TypeScript | 5.9.3 | Stable exact version within reference starter ^5.2.2; newer compiler migration deferred [TYPESCRIPT, STARTER] |
| Vite | 8.3.4 | Node 24 satisfies declared engine; local Node 22.7 does not [VITE] |
| Tailwind CSS | 4.3.3 | Meets Filament 4.1+ floor [TAILWIND, FILAMENT-REQ] |
| PHPUnit | 13.4.3 | PHP >=8.4.1; reviewed Laravel framework test range includes ^13.0.3 [PHPUNIT, LARAVEL-REQ] |
| PHPStan | 2.3.1 | Stable exact release and reviewed Laravel framework dev pin [PHPSTAN, LARAVEL-REQ] |

The reference starter is contextual only: its current scripts, TypeScript/checker choices and PHPUnit 12 range must not be copied blindly into this proposed custom manifest. PHPUnit 13 is a deliberate proposed harness choice; any starter adjustment needs explicit review. Vue checker, Laravel/Vue Vite plugins, PHP formatter, Laravel-specific analysis extension, browser tooling and remaining transitive dependencies still need exact compatible pins.

## Remaining gates

- Owner approves or amends this candidate matrix; exact direct pins do not replace complete lockfiles.
- Review all integration/tool/plugin peer ranges and security advisories at bootstrap time; no solver or audit has run.
- Only after separate M0-P2 authorization, resolve locks and verify platform requirements, install/build, PostgreSQL integration, coverage and failure propagation.
- Verify any selected cloud environment and hosting independently. Do not install OS services or provision resources from this proposal.
- Keep provider/AI packages out of V1 bootstrap unless separately authorized by their phase.

## Official sources

All checked 17 October 2026; release metadata proves existence, not full stack interoperability. Main-branch starter material is time-sensitive contextual evidence.

- **PHP-SUPPORT**: [PHP 8.5 active support through 2027-12-31 and security through 2029-12-31.](https://www.php.net/supported-versions.php)
- **PHP-RELEASE**: [Current stable PHP source listed as 8.5.11.](https://www.php.net/downloads.php?source=Y)
- **LARAVEL-SUPPORT**: [Laravel 13 supports PHP 8.3-8.5, bug fixes through Q3 2027, security through 2028-03-17.](https://laravel.com/docs/13.x/releases)
- **LARAVEL-PIN**: [Exact Laravel release candidate pin.](https://github.com/laravel/framework/releases/tag/v13.37.0)
- **LARAVEL-REQ**: [PHP ^8.3; runtime API ^2.2; developer test ranges include PHPUnit ^13.0.3.](https://raw.githubusercontent.com/laravel/framework/v13.37.0/composer.json)
- **FILAMENT-REQ**: [PHP 8.2+, Laravel 11.28+, Tailwind 4.1+.](https://filamentphp.com/docs/5.x/introduction/installation)
- **FILAMENT-PIN**: [Exact Filament release candidate pin.](https://github.com/filamentphp/filament/releases/tag/v5.10.2)
- **FILAMENT-SUPPORT**: [Version 5 support milestones are relative to version 6 stable release, not fixed calendar dates.](https://filamentphp.com/docs/5.x/introduction/version-support-policy)
- **FILAMENT-METADATA**: [Allows Illuminate ^13.0 and requires Livewire ^4.4.7.](https://raw.githubusercontent.com/filamentphp/filament/v5.10.2/packages/support/composer.json)
- **NODE**: [Node 24.22.0 LTS released 2026-10-14; includes npm 11.19.1.](https://nodejs.org/en/blog/release/v24.22.0)
- **NODE-POLICY**: [Production use should follow Active or Maintenance LTS.](https://nodejs.org/en/about/previous-releases)
- **VITE**: [Vite 8.3.4 requires Node ^20.19.0 or >=22.12.0.](https://raw.githubusercontent.com/vitejs/vite/v8.3.4/packages/vite/package.json)
- **INERTIA-PHP**: [Adapter 3.5.1 supports Laravel ^13.0 and PHP ^8.2.](https://raw.githubusercontent.com/inertiajs/inertia-laravel/v3.5.1/composer.json)
- **INERTIA-VUE**: [Vue adapter 3.9.1 accepts Vue ^3.0.0.](https://raw.githubusercontent.com/inertiajs/inertia/v3.9.1/packages/vue3/package.json)
- **VUE**: [Exact stable Vue release.](https://github.com/vuejs/core/releases/tag/v3.5.44)
- **STARTER**: [Reference starter uses Inertia 3, Vue 3, Vite 8 and TypeScript ^5.2.2. Floating main is contextual evidence, not a reproducible scaffold pin.](https://raw.githubusercontent.com/laravel/vue-starter-kit/main/package.json)
- **TYPESCRIPT**: [Exact TypeScript 5.9.3 stable release, chosen within reference starter range; not a claim of latest major.](https://github.com/microsoft/TypeScript/releases/tag/v5.9.3)
- **COMPOSER**: [Composer 2.10.x receives fixes; exact 2.10.3 release listed; current channel requires PHP 7.2+.](https://getcomposer.org/download/)
- **POSTGRES**: [PostgreSQL 18.6 current minor; major 18 supported through 2030-11-14.](https://www.postgresql.org/support/versioning/)
- **LARAVEL-PG**: [First-party PostgreSQL support includes 10+.](https://laravel.com/docs/13.x/database)
- **PHPUNIT**: [PHPUnit 13.4.3 requires PHP >=8.4.1.](https://raw.githubusercontent.com/sebastianbergmann/phpunit/13.4.3/composer.json)
- **PHPSTAN**: [Exact PHPStan release, also used in reviewed Laravel framework dev metadata.](https://github.com/phpstan/phpstan/releases/tag/2.3.1)
- **TAILWIND**: [Exact Tailwind 4.3.3 release; satisfies Filament documented minimum.](https://github.com/tailwindlabs/tailwindcss/releases/tag/v4.3.3)
