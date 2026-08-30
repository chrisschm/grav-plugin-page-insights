# Architecture

This document explains how the plugin is built and *why* certain decisions were made. It's aimed
at contributors who want to change code, not at end users configuring the plugin (see `README.md`
for that). *(Eine deutsche Kurzfassung findest du am Ende dieser Datei.)*

**Larger topics live in their own files**, linked from "Further documentation" below - this file
covers project structure, the two-Admin-UI data layer, the generic query filter, the config
blueprint, and two operational gotchas (Composer/autoloader, CI) that don't belong anywhere more
specific. General programming conventions stay here; schema, geolocation, admin-UI mechanics,
maintenance tooling, and bug history each have their own file now.

## Purpose

Collects and visualizes page-view statistics for a Grav site (page views, unique visitors/users,
browsers, platforms, countries) and exposes them inside the Grav Admin - both Classic Admin
(Grav < 2.0) and Admin Next / Admin2 (Grav 2.0+, via the official `api` plugin). Forked from
[Page Stats](https://github.com/francodacosta/grav-plugin-page-stats) by Nuno Costa after the
Admin2 migration left it functional (still collecting data) but entirely invisible in the new
Admin UI, with the upstream maintainer largely inactive. Two upstream PRs against Page Stats
(#54, #56) were later merged, and development continues here independently under the new name;
see `README.md` for the full history.

## Design goals

These apply to any future change (see also `CONTRIBUTING.md`):

- Dual-Admin compatibility: a change to the Admin2/Grav 2.0 side must never break the Classic
  Admin/Grav 1.x side, which is kept working (bugfixes, no regressions) until its Grav-side EOL.
  Beyond that baseline, whether a genuinely *new* feature also gets built for Classic Admin is a
  case-by-case call, not an automatic requirement: a new read-only display (e.g. the HTTP status
  codes widget, added to both admin UIs) is fine and welcome there too. A new *actionable*
  surface - anything that triggers a mutation and would need its own ongoing support/maintenance
  in the old environment (e.g. the database maintenance dialog, deliberately Admin2-only, and,
  since 2026-08-24, the "Scan detection" pattern-management view for the same reason) - isn't
  something to open up there without a specific reason; active development targets Admin2 only.
- No third-party runtime Composer dependency (the last one, `ip2location/ip2location-php`, was
  removed 2026-08-15 - see [`GEOLOCATION.md`](GEOLOCATION.md)). `vendor/` is still deliberately
  committed to the repository (see "Composer & the compiled autoloader" below) so installation
  stays a plain file copy - no build step, no `composer install` required on the target server;
  that's now purely a convenience for the plugin's own PSR-4 autoloading, not a
  dependency-vendoring concern.
- Must stay installable via GPM (once listed) or a manual ZIP drop, without any manual step by
  the end user beyond copying files.

## File layout

```
user/plugins/page-insights/
├── page-insights.php                      # events, IP/geo collection, Classic Admin + Admin2 wiring
├── page-insights.yaml                     # default configuration
├── blueprints.yaml                        # Admin config form (3 tabs, see below)
├── composer.json                          # no third-party runtime dependency (see GEOLOCATION.md)
├── classes/
│   ├── Stats.php                          # data layer (PDO/SQLite), UI-independent
│   ├── AutoSchedule.php                   # deterministic per-install cron scheduling (see MAINTENANCE.md)
│   ├── RelativeDate.php                   # "--older-than"/"..._older_than" parsing, shared by CLI + scheduler
│   ├── LocalizedDate.php                  # locale-aware date/time formatting (see ADMIN-UI.md)
│   ├── Api/PageInsightsApiController.php  # REST controller consumed by Admin2
│   └── Geolocation/                       # self-built country lookup (see GEOLOCATION.md)
├── cli/                                   # `bin/plugin page-insights <command>` (see MAINTENANCE.md)
│   ├── GeoDbUpdateCommand.php             # geo-db:update
│   ├── PruneCommand.php                   # prune
│   ├── EventsPruneOrphansCommand.php      # events:prune-orphans
│   ├── VacuumCommand.php                  # vacuum
│   ├── RollupBuildCommand.php             # rollup:build
│   ├── PruneBotsCommand.php               # prune:bots
│   ├── PruneNotFoundCommand.php           # prune:notfound
│   └── ScanPatternsImportCommand.php      # scan-patterns:import (see "Scan detection" below)
├── data/
│   ├── geo-country-index.bin              # NOT shipped/committed - built on demand, see GEOLOCATION.md
│   ├── scan-patterns-webexploits.txt      # default scan-pattern seed list, see "Scan detection" below
│   └── migrations/{1..10}.sql + MUST_MIGRATE # schema upgrades, applied by Stats.php on boot
│                                           # (schema/format details: DATABASES.md)
├── admin-next/pages/page-insights.js      # Admin2 dashboard (Web Component, Shadow DOM)
├── themes/admin/templates/                # Classic Admin Twig templates (9 sub-pages, see below)
│   └── widgets/geo-db-status.html.twig    # geo index status + "Update now" (see GEOLOCATION.md)
├── pages/*.md                             # Classic Admin virtual page stubs (one per sub-page)
├── languages/{en,de,fr}.yaml              # Admin panel translations (Codeberg Translate/Weblate)
└── vendor/                                # committed on purpose, see below
```

Two runtime-generated files live outside this tree entirely, in `user/data/page-insights/`
(sibling to `user/plugins/`, not inside it) rather than in the plugin's own directory: the SQLite
hit database (`db` config key, default `user/data/page-insights.sqlite`) and, since 2026-08-17,
the geo country index (`geo_db_index_path` config key, default
`user/data/page-insights/geo-country-index.bin`; before that it lived at
`data/geo-country-index.bin` *inside* the plugin directory, shown above). Both config keys just
hold a plain path string, relative to the Grav root - no stream/locator indirection - which works
because Grav requests always run with the Grav root as the working directory. The reason both live
outside `user/plugins/page-insights/`: GPM replaces the *entire* plugin directory on every update
(see [`HISTORY.md`](HISTORY.md) #8) - anything written inside it, e.g. the geo index before this
move, is silently lost on the next update. `user/data/` isn't touched by a plugin update, so it
survives. See [`DATABASES.md`](DATABASES.md) for what's actually inside each of these two files.

## Two Admin UIs, one data layer

Both Admin UIs read from the same `classes/Stats.php` - nothing about data collection or storage
differs between them. What differs is purely presentation:

- **Classic Admin** (Grav < 2.0 / the `admin` plugin): nine separate virtual pages
  (`onAdminPage` routes each to a Twig template under `themes/admin/templates/`), each configured
  via its own section in the "Grav 1.7 / Classic Admin" blueprint tab. This is not dead code -
  it's actively used by sites still on Classic Admin, and both branches must be kept working when
  changing shared behaviour.
- **Admin2** (Grav 2.0+ / the `api` plugin): a single Web Component
  (`admin-next/pages/page-insights.js`, Shadow DOM) that consolidates all nine Classic Admin
  sub-pages into one dashboard, talking to `classes/Api/PageInsightsApiController.php` over REST.
  `onApiRegisterRoutes`/`onApiSidebarItems`/`onApiPluginPageInfo` are no-ops when the `api` plugin
  isn't installed - Grav simply never fires them, so a Classic-Admin-only site is unaffected. See
  [`ADMIN-UI.md`](ADMIN-UI.md) for Admin2's client-side routing, i18n bridge, and localized date
  formatting.

If you change something in `Stats.php` that's exposed to the user, check whether both the
Classic Admin Twig templates *and* the Admin2 REST endpoints need to reflect it.

## Backend: generic query filter (`Stats::query()`)

`Stats::query()` builds `$key = :$key` SQL conditions generically from a `$params` array.
`topCountries()`, `topBrowsers()`, `topPlatforms()`, `recentPages()`, `pagesSummary()`, and
`siteSummary()` all accept such a `$params` filter (e.g. `['route' => ...]`, `['user' => ...]`,
`['ip' => ...]`). **Before adding a new, separately-filtered method, check whether this existing
mechanism already covers the use case** - most new filtering needs so far have.

**The filter also accepts array values**, generating `key IN (:k0, :k1, ...)` instead of an
equality comparison - and an *empty* array deliberately generates an unsatisfiable `1 = 0`
condition rather than silently falling back to unfiltered (a caller passing `[]` almost certainly
meant "match nothing", not "ignore this filter"). Not obvious from the equality-only examples
above - worth knowing before reaching for a bespoke `IN`-query method.

`userDetail()` accepts `user` **or** `ip` as a query parameter, since anonymous visitors have no
username but remain individually trackable via their `ip` column. `summary()` optionally accepts
`route`/`user`/`ip` to scope the time-series data behind the detail-page charts; without them it
falls back to the unfiltered dashboard behaviour.

Note for PHP-version-sensitivity: `query()` uses `str_contains()` (PHP 8.0+) on every filtered
call, with no polyfill in the production dependencies - this is why `composer.json` requires
`"php": ">=8.0"` and `config.platform.php` is pinned to `8.0`. When checking PHP compatibility of
a change, check *function availability*, not just syntax (`match`, `enum`, `readonly`, `?->`) -
`str_contains()` was missed this way once already (see [`HISTORY.md`](HISTORY.md) #2).

## Config blueprint: 3 tabs

`blueprints.yaml` is organized into three tabs: **Allgemein/General** (applies to both Admin
versions), **Grav 1.7 / Classic Admin** (per-widget settings for the nine Classic Admin
sub-pages - actively used, not legacy), **Grav 2.0 / Admin2** (currently an info box, placeholder
for future Admin2-specific options). `type: tabs` is a transparent layout type with no effect on
stored config paths.

Important Admin1/Admin2 blueprint incompatibility: a `type: section` field is only visible in
Admin2 when `title` is also set (Admin1 renders `text` alone just fine). `type: display` only has
a Twig template in Admin2. The common denominator that works on both: `section` + `title` +
`text` + `fields: {}`.

## Composer & the compiled autoloader (important operational gotcha)

Grav does **not** run `composer install` for plugins on any installation path (GPM or Admin ZIP
upload both just copy/extract files). Because `vendor/` is deliberately committed to this
repository (see "Design goals"), that's normally invisible to users - but it means the
**compiled** autoloader files (`vendor/composer/autoload_static.php`, `autoload_psr4.php`,
`autoload_classmap.php`, `vendor/composer/installed.php`, `vendor/composer/platform_check.php`)
are static, generated artifacts, not derived from `composer.json` at runtime.

**Any change to `classes/` namespaces, file layout, or `composer.json`'s `autoload`/`require`
section requires running `composer dump-autoload` and committing the regenerated files -
otherwise a fresh checkout throws `Class "..." not found` even though `composer.json` itself is
correct.** This has already happened once in this project's history (see
[`HISTORY.md`](HISTORY.md) #1) and is easy to miss because an existing, already-`vendor/`-populated
working copy keeps running fine on the old autoloader - only a genuinely fresh checkout/clone
reveals the mismatch. A second, related pitfall - a *different* plugin's compiled autoloader
declaring the same class name when both run in the same PHP process - is covered in
[`HISTORY.md`](HISTORY.md) #29.

Similarly, `composer.lock`'s `content-hash` and `platform-overrides` fields are a snapshot taken
at the time `composer update --lock` was last run against `composer.json`. If `composer.json`
changes (e.g. the `require.php` / `config.platform.php` version) without a matching
`composer update --lock`, the lock file silently drifts out of sync - `composer install` still
succeeds locally against an already-populated `vendor/`, but a clean environment (a CI runner,
in particular) re-validates the lock file against the current platform and fails outright if the
two disagree. Run `composer validate` after any `composer.json` edit to catch this before it
reaches CI.

## CI (`.forgejo/workflows/lint.yml`)

Runs on Codeberg only (`if: github.server_url != 'https://github.com'` - the GitHub mirror never
executes it) on every push to `develop` and every PR against `main`/`develop` (deliberately not
`translate`, since that branch only touches `languages/*.yaml`). Installs dependencies via
`composer install --no-interaction --prefer-dist`, then runs `php -l`, `node --check`, and a
sandboxed Twig syntax check (with `registerUndefinedFunctionCallback`/`registerUndefinedFilter
Callback` stubs, since Grav's own Twig functions like `url()`/`nicetime()`/`t()` are unknown to
an isolated Twig environment) - but only on files that actually changed in the triggering
push/PR. A failure in the "Abhängigkeiten installieren" (`composer install`) step itself, before
any syntax check runs, points at the `composer.lock` drift described above, not at a code issue.
A force-push interacting badly with this diff-scoping logic once caused the check to silently
skip everything and report success - see [`HISTORY.md`](HISTORY.md) #25.

## Scan detection

Opt-in (`scan_detection`, default `false`) feature that matches recently collected 404 hits
against an admin-curated list of known vulnerability-scan paths (`scan_patterns` table) and raises
an alert (`scan_alerts` table) once one IP racks up too many distinct matches in too short a
window - typically automated probing (a scanner walking through `/wp-login.php`, `/.git/config`,
`/phpMyAdmin/index.php` and similar known-vulnerable paths) rather than a stray broken link. Added
2026-08-24 after noticing exactly this pattern in `data` during unrelated debugging. See
[`DATABASES.md`](DATABASES.md) for the schema and [`MAINTENANCE.md`](MAINTENANCE.md) for the
scheduler job, CLI import command, and Admin2 "Scan detection" view.

Two design decisions worth calling out, both driven by the "no per-request performance cost"
requirement this feature started from:

- **Detection is entirely a scheduled batch job (`registerScanDetectionJob()`, every 5 minutes),
  never a request hook.** `onPageInitialized` already logs every 404 (route, IP, timestamp) via
  the existing `collect()` path - scan detection reads *that*, after the fact, rather than adding
  its own per-request pattern-matching. A visitor's request is never slowed down or blocked by
  this feature, regardless of how large `scan_patterns` grows.
- **The 5-minute cadence isn't built on `AutoSchedule`** (unlike `geo_db_auto_update`/
  `data_auto_prune`/`rollup_auto_build`): that class only ever derives a `disabled`/`daily`/
  `weekly`/`monthly` point in time, since none of its other callers needed anything finer.
  `registerScanDetectionJob()` uses a fixed `*/5 * * * *` cron expression instead, gated by a
  plain boolean config flag. This needs no separate crontab entry or admin setup beyond the config
  toggle - the site's one `bin/grav scheduler` cron entry already runs every minute regardless (a
  Grav Admin "custom scheduled jobs" entry, the other mechanism considered, would have needed the
  admin to configure a shell command by hand for something this plugin can register itself).

Alerting has two independent channels, both reading `scan_alerts` fresh - neither is the source of
truth for the other:

- **Admin2 dashboard banner** (`onApiDashboardNotifications`, no-op without the `api` plugin -
  same pattern as `onApiSidebarItems`/`onApiRegisterRoutes`): contributes one dismissible "top"
  notification per currently-open alert every time the dashboard loads, via the api plugin's own
  notification mechanism (`DashboardController::notifications()` - see that class for the
  location/dismiss/`reappear_after` schema). Always reflects live state.
- **Email**, optional (`scan_detection_alert_email`): sent directly via
  `Grav\Plugin\Email\Utils::sendEmail()` (checked with `is_callable()` first, so nothing breaks
  on a site without the separate, official, `bin/gpm install email` plugin), called from inside the
  job's own closure - deliberately NOT via Grav-Core's `Scheduler\Job::email()` (the mechanism the
  Admin's "custom scheduled jobs" UI exposes as its own "E-Mail" field), because that only works
  for jobs registered via `addCommand()`. For an `addFunction()`-based job like this one,
  `Job::email()` triggers an uncaught fatal PHP error trying to cast the job's Closure to a string
  - an upstream Grav-Core bug, already fixed on `develop` (2026-08-27) but not yet released as of
  this writing (see `HISTORY.md` #33 for the commit and version status). Only sent once per
  alert (`scan_alerts.notified_at`) - a still-ongoing incident doesn't re-email every five minutes
  for as long as it continues.

`scan_patterns` starts empty on every install - population is a separate, deliberate step
(`bin/plugin page-insights scan-patterns:import`, seeded from the bundled
`data/scan-patterns-webexploits.txt` snapshot, or manual entries via the Admin2 "Scan detection"
view). Both `importScanPatterns()` (the CLI command) and `addScanPattern()` (the Admin2 "add"
form) insert-only-if-missing (`INSERT OR IGNORE` against the `UNIQUE pattern` column) - re-running
the import after an admin has disabled or added their own patterns never touches those rows.

## Further documentation

Larger topics that used to live in this file now have their own document:

- [`DATABASES.md`](DATABASES.md) - schema, indexes, connection pragmas, and the geo country
  index's on-disk binary format, with the design decisions behind each.
- [`GEOLOCATION.md`](GEOLOCATION.md) - the country-lookup subsystem: data source, the three
  classes that build and read the index, and the prebuilt-vs-raw update modes.
- [`ADMIN-UI.md`](ADMIN-UI.md) - Admin2's client-side sub-routing, its i18n bridge, localized
  date formatting on both admin sides, and the "Hide bots" filter.
- [`MAINTENANCE.md`](MAINTENANCE.md) - CLI commands, the Admin2 database maintenance dialog, scan
  detection (pattern list, alerts), and the automatic scheduler jobs.
- [`HISTORY.md`](HISTORY.md) - a numbered list of non-obvious past bugs, their root cause, and
  the reasoning behind each fix - useful context before touching related code.

---

## Auf Deutsch (Kurzfassung)

Diese Datei richtet sich an Contributor, die am Code arbeiten wollen (Endnutzer-Doku steht in
`README.md`). Größere Themen stehen inzwischen in eigenen Dateien (siehe "Further documentation"
oben): Datenbankschemata in `DATABASES.md`, Geolocation in `GEOLOCATION.md`, Admin-UI-Mechanik
(Sub-Routing, i18n, Datumsformatierung, Bots-Filter) in `ADMIN-UI.md`, Wartungswerkzeuge (CLI,
Wartungsdialog, Scan-Erkennung, Scheduler-Jobs) in `MAINTENANCE.md`, und die Bug-Historie in
`HISTORY.md`. Diese Datei selbst behandelt nur noch Projektstruktur, die geteilte Datenschicht, den
generischen Query-Filter, das Config-Blueprint und zwei betriebliche Stolpersteine
(Composer/Autoloader, CI).

Zwei Admin-Oberflächen (Classic Admin, neun Twig-Unterseiten; Admin2, eine Web-Component) teilen
sich dieselbe Datenschicht (`classes/Stats.php`) - Unterschiede betreffen ausschließlich die
Präsentation, nicht Erfassung/Speicherung. Der generische Spalten-Filter-Mechanismus
(`Stats::query()`, `$params`-Array) deckt die meisten neuen Filter-Anforderungen bereits ab - vor
einer neuen, eigens gefilterten Methode immer erst prüfen. Er akzeptiert auch Array-Werte
(`key IN (...)`), wobei ein leeres Array bewusst `1 = 0` erzeugt statt ungefiltert durchzulassen.

Wichtigster operativer Stolperstein: `vendor/` ist bewusst committet (Grav installiert
Composer-Abhängigkeiten nie selbst), aber die **kompilierten** Autoloader-Dateien
(`vendor/composer/autoload_*.php`) werden nicht automatisch aus `composer.json` neu erzeugt.
Jede Namespace-/Datei-Umbenennung braucht zwingend `composer dump-autoload` + Commit, sonst droht
`Class "..." not found` bei jedem frischen Checkout - genau das ist bereits einmal passiert (siehe
`HISTORY.md`). Ebenso kann `composer.lock`s `content-hash`/`platform-overrides` nach einer
`composer.json`-Änderung veraltet zurückbleiben, wenn `composer update --lock` nicht nachgezogen
wird - lokal mit bereits vorhandenem `vendor/` unauffällig, in einer sauberen CI-Umgebung aber ein
harter Fehlschlag beim `composer install`-Schritt.

Zwei Admin-UI-Blueprint-Eigenheiten: `type: section` braucht in Admin2 zusätzlich ein `title`, um
sichtbar zu werden; `type: display` existiert nur in Admin2 sinnvoll. Gemeinsamer Nenner für
Infoboxen, die auf beiden Admin-Versionen funktionieren sollen: `section` + `title` + `text` +
`fields: {}`.
