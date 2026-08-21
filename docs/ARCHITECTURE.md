# Architecture

This document explains how the plugin is built and *why* certain decisions were made. It's aimed
at contributors who want to change code, not at end users configuring the plugin (see `README.md`
for that). *(Eine deutsche Kurzfassung findest du am Ende dieser Datei.)*

**Database schemas live in a separate file.** Table/column layout, indexes, connection pragmas,
and the geo country index's binary format - including the design decisions behind them where they
matter for correctness or performance - are documented in
[`DATABASES.md`](DATABASES.md), not here, so there's exactly one place to update when a schema
changes. This file links to it wherever relevant; general programming conventions (the query
filter mechanism, i18n, Composer/autoloader, CI, etc.) stay here as before.

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
  in the old environment (e.g. the database maintenance dialog, deliberately Admin2-only) - isn't
  something to open up there without a specific reason; active development targets Admin2 only.
- No third-party runtime Composer dependency (the last one, `ip2location/ip2location-php`, was
  removed 2026-08-15 - see "Geolocation" below). `vendor/` is still deliberately committed to the
  repository (see "Composer & the compiled autoloader" below) so installation stays a plain file
  copy - no build step, no `composer install` required on the target server; that's now purely a
  convenience for the plugin's own PSR-4 autoloading, not a dependency-vendoring concern.
- Must stay installable via GPM (once listed) or a manual ZIP drop, without any manual step by
  the end user beyond copying files.

## File layout

```
user/plugins/page-insights/
├── page-insights.php                      # events, IP/geo collection, Classic Admin + Admin2 wiring
├── page-insights.yaml                     # default configuration
├── blueprints.yaml                        # Admin config form (3 tabs, see below)
├── composer.json                          # no third-party runtime dependency (see Geolocation below)
├── classes/
│   ├── Stats.php                          # data layer (PDO/SQLite), UI-independent
│   ├── AutoSchedule.php                   # deterministic per-install cron scheduling (see "Automatic scheduling")
│   ├── RelativeDate.php                   # "--older-than"/"..._older_than" parsing, shared by CLI + scheduler
│   ├── Api/PageInsightsApiController.php  # REST controller consumed by Admin2
│   └── Geolocation/                       # self-built country lookup (see "Geolocation" below)
├── cli/                                   # `bin/plugin page-insights <command>` (see "CLI commands")
│   ├── GeoDbUpdateCommand.php             # geo-db:update
│   ├── PruneCommand.php                   # prune
│   ├── EventsPruneOrphansCommand.php      # events:prune-orphans
│   └── VacuumCommand.php                  # vacuum
├── data/
│   ├── geo-country-index.bin              # NOT shipped/committed - built on demand, see below
│   └── migrations/{1..5}.sql + MUST_MIGRATE  # schema upgrades, applied by Stats.php on boot
│                                           # (schema/format details: DATABASES.md)
├── admin-next/pages/page-insights.js      # Admin2 dashboard (Web Component, Shadow DOM)
├── themes/admin/templates/                # Classic Admin Twig templates (9 sub-pages, see below)
│   └── widgets/geo-db-status.html.twig    # geo index status + "Update now" (see "Geolocation")
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
(see "Notable past bugs" #8) - anything written inside it, e.g. the geo index before this move, is
silently lost on the next update. `user/data/` isn't touched by a plugin update, so it survives.
See [`DATABASES.md`](DATABASES.md) for what's actually inside each of these two files.

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
  isn't installed - Grav simply never fires them, so a Classic-Admin-only site is unaffected.

If you change something in `Stats.php` that's exposed to the user, check whether both the
Classic Admin Twig templates *and* the Admin2 REST endpoints need to reflect it.

## Backend: generic query filter (`Stats::query()`)

`Stats::query()` builds `$key = :$key` SQL conditions generically from a `$params` array.
`topCountries()`, `topBrowsers()`, `topPlatforms()`, `recentPages()`, `pagesSummary()`, and
`siteSummary()` all accept such a `$params` filter (e.g. `['route' => ...]`, `['user' => ...]`,
`['ip' => ...]`). **Before adding a new, separately-filtered method, check whether this existing
mechanism already covers the use case** - most new filtering needs so far have.

`userDetail()` accepts `user` **or** `ip` as a query parameter, since anonymous visitors have no
username but remain individually trackable via their `ip` column. `summary()` optionally accepts
`route`/`user`/`ip` to scope the time-series data behind the detail-page charts; without them it
falls back to the unfiltered dashboard behaviour.

Note for PHP-version-sensitivity: `query()` uses `str_contains()` (PHP 8.0+) on every filtered
call, with no polyfill in the production dependencies - this is why `composer.json` requires
`"php": ">=8.0"` and `config.platform.php` is pinned to `8.0`. When checking PHP compatibility of
a change, check *function availability*, not just syntax (`match`, `enum`, `readonly`, `?->`) -
`str_contains()` was missed this way once already (see "Notable past bugs").

## Admin2 sub-routing: query parameters, not path segments

Admin2's SvelteKit client router only knows a single dynamic segment per plugin page
(`/plugin/[slug]`, no catch-all). A deeper, self-built path segment (e.g. `/plugin/page-insights/
page-detail`) would fail client-side navigation, even though the server (`admin2.php`) answers
every sub-route correctly with the SPA shell. **Solution:** Page Detail and User Detail are
separate view *states* of the same fixed route, addressed purely via query string
(`?view=page-detail&route=...`, `?view=user-detail&user=...`/`?ip=...`), driven by plain
`history.pushState()`/`popstate`. The isolated custom element has no access to SvelteKit's
`$app/navigation`, but the native browser mechanism is sufficient, since SvelteKit's own helpers
do the same thing internally. Verified live: hard reload on all three URL shapes works, browser
back/forward works, and the currently selected time range survives switching between detail
views (shared `#range` state).

Both detail views are assembled entirely from existing dashboard building blocks (`_chartCard()`,
`_lineChart()`, `_bars()`, `_table()`) - no separate rendering code path to maintain.

## Admin2 i18n

Unlike Classic Admin's Twig templates (which resolve `'PLUGIN_PAGE_INSIGHTS.X'|t` automatically
against the active admin language), `admin-next/pages/page-insights.js` is a plain Web Component
with no built-in connection to Grav's translation system - until this was wired up, every UI
string in the Admin2 dashboard was a hardcoded English literal, regardless of the admin's
configured language (while the Classic Admin config form for the same plugin correctly rendered
translated). Confirmed there is no plugin-side workaround needed: `grav-admin-next` (the
SvelteKit SPA behind Admin2) itself installs a read-only global bridge for exactly this,
`window.__GRAV_I18N` (`src/lib/stores/i18n.svelte.ts`, doc comment: *"Global i18n bridge for
plugin web-component bundles ... that aren't built against admin-next's Svelte runtime"*) - the
same pattern already used here for `window.__GRAV_TOAST`. Interface: `t(key, params?)`,
`tHtml(key, params?)`, `has(key)`, `locale`, `dir`, `subscribe(fn)`.

Two things worth knowing before touching this:

- **No `%s`/ICU substitution for plugin keys.** `t()`'s ICU `params` support only applies to keys
  registered under admin-next's own `ICU.*` namespace (its core UI strings, translated via
  translations.getgrav.org) - plugin keys arrive as plain strings sourced from this repo's
  `languages/*.yaml` via the `/translations` API endpoint and are returned verbatim. `page-insights.js`'s
  `_t()`/`_tf()` helpers wrap the bridge: `_t(key, fallback)` returns the translation or the given
  English fallback if the bridge/key is unavailable (checking `has()` explicitly, since an
  unknown key still humanizes into readable-ish text rather than returning `undefined`); `_tf()`
  additionally does client-side `%s` substitution, mirroring the sprintf-style positional args
  Classic Admin's Twig templates already get for free from Grav's `|t(a, b, ...)` filter (see
  `GEO_DB_BUILT_STATUS` in `themes/admin/templates/widgets/geo-db-status.html.twig` - same key,
  same placeholder order, reused as-is by the Admin2 side of the geo-db status line).
- **No reactivity.** Everything in this file is plain `innerHTML` template strings, not a
  reactive framework - a live admin language switch wouldn't otherwise be reflected until a full
  reload. `connectedCallback()` subscribes to `window.__GRAV_I18N.subscribe()` and re-runs
  `_render()` + `_load()` on a locale change (unsubscribed in `disconnectedCallback()`). This
  costs an extra API round-trip on every language switch (simplest correct behaviour across all
  three views - the dashboard alone could re-render cheaply from cached state, but the detail
  views don't keep their last response around) - an acceptable trade-off given how rarely a user
  changes admin language mid-session.

New Admin2-only strings (dashboard chrome with no Classic Admin equivalent - "Loading…", "No
data.", range-picker buttons, etc.) live under a new `PLUGIN_PAGE_INSIGHTS.ADMIN2.*` block in
`languages/{en,de,fr}.yaml`; everything with a direct Classic Admin equivalent (`TOP_COUNTRIES`,
`RECENTLY_VIEWED_PAGES`, the `GEO_DB_*` geo-status keys, etc.) reuses the existing top-level keys
rather than duplicating them.

**Short-code vs. BCP47 language files - see "Notable past bugs" #10 and #11.** `/translations` (the
API endpoint the bridge above actually calls) resolves plugin strings by the *exact* admin locale
code (`de-DE`), while this plugin's `languages/*.yaml` use the short-code convention (`de`) - two
buckets that Grav core never merges on its own.
`PageInsightsPlugin::mergeAdmin2TranslationAliases()` (hooked into `onPluginsInitialized()` -
**not** an `onApi*` event, see #11 for why) bridges this at runtime; if `has()` ever returns `false`
for a key that's clearly present in `languages/de.yaml`, check there before assuming the key itself
is missing.

Not yet covered: chart x-axis date labels (`_formatDayLabel()`) are still a fixed `DD.MM.` format
regardless of admin language - locale-aware date formatting remains a separate, still-open README
To Do item.

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

## Geolocation (`classes/Geolocation/`)

**Country-only, self-built, never shipped in the repo.** Until 2026-08-15 this wrapped the
`ip2location/ip2location-php` Composer package around a committed `data/IP2LOCATION-LITE-DB3.BIN`
(country+region+city). That file alone was ~47 MB - over 90% of the plugin's total checkout size,
and shipped in every GitHub release archive since `release-from-tag.yml` just archives the tagged
tree. Worse, IP2Location LITE's own terms prohibit exactly that ("third party database
repository" redistribution is explicitly disallowed - only a per-user, individually registered
download is permitted). Investigating the fix surfaced two more findings that changed the design
rather than just the data source: (1) `region`/`city` were written to the stats DB on every hit
but never read back anywhere - no query, no admin UI - only `countryCode` was ever used; (2) *any*
snapshot committed once per plugin release goes stale between releases regardless of vendor or
license, which a fixed-file model can't fix at all. (Addendum, 2026-08-16: the removed binary was
still reachable via git history through the `3.0.0`/`3.0.1` tags - see "Notable past bugs" #9.)

The replacement (`RirStatsParser`, `CountryIndexBuilder`, `CountryLookup`) is built from the
combined **RIR delegated-stats** file - the same public, daily-updated ground-truth allocation
data (RIPE NCC/ARIN/APNIC/LACNIC/AFRINIC via the NRO) that commercial GeoIP vendors themselves
build on top of, published free of any license/token/account gate at
`https://ftp.ripe.net/pub/stats/ripencc/nro-stats/latest/nro-delegated-stats` (format spec:
`https://ftp.ripe.net/ripe/stats/RIR-Statistics-Exchange-Format.txt`). Only country-level data
exists in this source in the first place, which matches finding (1) above - `GeolocationData`
keeps its `countryName()`/`region()`/`city()` accessors (so `Stats.php`'s existing column
bindings don't need a schema migration) but they now just return `'unknown'`; only `countryCode()`
carries real data.

- **`RirStatsParser`** - pure text-in/ranges-out. Parses the pipe-delimited format, keeps only
  `type=ipv4|ipv6` records with `status=allocated|assigned` (skips `available`/`reserved`/`asn`),
  normalizes IPv4 ranges to `[startInt, endInt]` and IPv6 ranges to `[start16ByteString,
  end16ByteString]` (host bits set from the CIDR prefix length). No HTTP, no file I/O - kept
  independently testable against a small in-memory fixture instead of the real ~20-30 MB file
  (which this sandbox's network policy couldn't download directly anyway - see "Notable past
  bugs"/session notes for how the parser was verified instead).
- **`CountryIndexBuilder`** - fetches the source URL (curl if available, `file_get_contents`
  fallback), sorts ranges per IP version, and **gap-fills**: every possible address must resolve
  to exactly one entry, so unallocated/reserved holes between real ranges get an explicit
  `UNKNOWN_CC` ("ZZ", ISO 3166-1's reserved "unknown country" code) entry rather than being left
  out - that's what lets `CountryLookup` always do a plain "greatest start <= address" binary
  search with no separate end-of-range check. Adjacent same-country entries are merged. Writes its
  own small, fully self-documented binary format (see `DATABASES.md` for the exact byte layout and
  the design decisions behind it, also mirrored in the class doc comment) - deliberately not
  IP2Location's BIN format, nothing left to reverse-engineer.
- **`CountryLookup`** - the read side, instantiated fresh on every single page hit
  (`PageInsightsPlugin::collectPageData()`). Binary search directly against the file via
  `fseek`/`fread` per probe (nothing loaded into memory up front, same approach the old
  IP2Location library used) - construction and lookup both degrade to a no-op/`null` if the index
  file doesn't exist yet or is corrupt, never throwing. A missing or stale geo database must never
  break page collection.
- **Building the index is never automatic** - not at install time, not on the page-request path,
  not on a timer (yet). It's an explicit admin action, triggered next to the "Top countries" stat
  in both admin UIs rather than from the config form (it's an action tied to that stat, not a
  setting - `section_geolocation` in `blueprints.yaml` only holds the source configuration and,
  since 2026-08-17, the *destination* path (`geo_db_index_path`, see the file-layout note above) -
  where to write the result, as opposed to `geo_db_source_mode`/`geo_db_prebuilt_url`/
  `geo_db_source_url` below, which control where the build reads from):
  - **Admin2**: a button in the "Top countries" card in `admin-next/pages/page-insights.js`
    (`_updateGeoDb()`/`_geoStatusHtml()`), calling `PageInsightsApiController::rebuildGeoDb()`
    (`POST /page-insights/geo-db/rebuild`, `api.system.write`) and `::geoDbStatus()`
    (`GET /page-insights/geo-db/status`, `api.system.read`). These REST endpoints only exist when
    `grav-plugin-api` is installed (see "Notable past bugs" #6 below for why that matters) - the
    card degrades to showing no status/control rather than failing if it's missing or 404s.
  - **Classic Admin**: a plain nonce-protected self-post form
    (`themes/admin/templates/widgets/geo-db-status.html.twig`, included from both the dashboard
    widget and the dedicated Top Countries page) handled by
    `PageInsightsPlugin::handleGeoDbRebuildPost()`, called from the top of `onAdminPage()`. No
    AJAX - this plugin's classic-admin side is otherwise fully server-rendered, and there's no
    core admin task convention for a plugin-defined action like this one.

  Both paths call `GeoDbUpdater::update()` - there is no third, separate implementation of the
  rebuild itself.

  Both a successful rebuild and a failure write a `grav.log` line (`addInfo()`/`addError()`
  respectively, including the triggering admin's username) - visible via Admin2's Tools -> Logs
  ("Grav System Log") without needing DB/API access, so an admin can ask a bug reporter to check
  the log rather than reproduce the issue themselves. Deliberately just `grav.log`, not the `api`
  plugin's separate Audit Trail (`classes/Api/Audit/` there, off by default) - that system only
  captures HTTP requests through its own router, so it can't see the scheduled/CLI-triggered path
  anyway, and would add a dependency on that plugin's internal (undocumented) classes for a need
  `addInfo()` already covers.

### Two update modes: prebuilt (default) vs. raw RIR build (`GeoDbUpdater`)

As of 2026-08, rebuilding the index has two modes, selected via the `geo_db_source_mode` config
field and dispatched by the new `GeoDbUpdater` class (both admin surfaces above call this instead
of `CountryIndexBuilder` directly now):

- **`prebuilt` (default):** `CountryIndexBuilder::fetchPrebuilt()` downloads an *already-built*
  index from a small **companion repository** (`page-insights-geo-db`, separate from this plugin's
  repo) whose only job is running a scheduled GitHub Actions workflow that checks out this
  repo's `classes/Geolocation` classes unchanged, runs the exact same `build()` pipeline described
  below, and publishes the result as a rolling `latest` GitHub Release asset. Consuming sites just
  download that asset (a few MB, not the ~54 MB raw RIR snapshot) and validate+install it
  (`CountryIndexBuilder::parseHeader()` checks the magic bytes and that the declared entry counts
  actually match the downloaded byte count, so a truncated/corrupt download is rejected *before*
  it overwrites a previously working index) - no parsing, no sorting, and therefore no elevated
  `memory_limit` requirement on the site itself. This exists because the two costs of building
  locally on every site - a large recurring download on top of shared-hosting traffic budgets, and
  the PHP-array memory overhead of parsing several hundred thousand individual RIR records (see
  "Notable past bugs" #8) - only need to be paid once centrally, on a CI runner with far more
  headroom than typical shared PHP hosting, rather than once per installation.
- **`raw` (opt-out):** `CountryIndexBuilder::build()`, unchanged from the original design below -
  downloads the full RIR snapshot and builds locally. Kept as an explicit, fully-documented escape
  hatch for anyone who doesn't want the companion repo as a middleman: it's a small commercial-free
  registry-run companion project, but "trust the plugin author's separate repo/CI pipeline, not
  just the plugin's own code" is still a real trust decision, and this mode means nobody is forced
  into it. Also the fallback if the companion project is ever unreachable, discontinued, or simply
  not preferred.

  Both modes have their own optional source-URL override field (`geo_db_prebuilt_url` /
  `geo_db_source_url` respectively) - empty uses `CountryIndexBuilder::DEFAULT_PREBUILT_URL` /
  `DEFAULT_SOURCE_URL`. Both fields are always shown in both admin UIs regardless of the selected
  mode (rather than conditionally hidden/shown) - deliberately, after the custom-Admin2-field
  fallback lesson (see "Notable past bugs" #6): the simplest, most reliably-identical-across-both-
  admin-UIs form beats a cleverer one.

`Ip::toNumber()`/`toIP()` (IPv4 <-> integer helpers, previously dead code kept only for a doc
mention) are now actually used by `CountryLookup`'s IPv4 binary search.

The previously-deferred "Scheduler-friendly console command for unattended per-site refresh" is
now `cli/GeoDbUpdateCommand.php` plus, for fully unattended operation with no crontab line of its
own, the automatic job registered by `PageInsightsPlugin::onSchedulerInitialized()` - see "CLI
commands" and "Automatic scheduling" below. Both call `GeoDbUpdater::update()`, so they pick up
either source mode identically to the two manual admin triggers above.

## CLI commands (`cli/`)

Grav auto-discovers any `Grav\Plugin\Console\<Name>Command` class in a plugin's `cli/<Name>Command.php`
(see Grav core's `PluginCommandLoader`) - no registration in `composer.json` or anywhere else is
needed, and `Symfony\Component\Console\*` comes from Grav core's own vendor tree, not this plugin's
(same reasoning as relying on Grav core's Scheduler classes, see below - avoids repeating the
vendor-bloat mistake `git` history already went through once, see "Notable past bugs" #9/#12).

- **`bin/plugin page-insights geo-db:update [--mode=prebuilt|raw]`** - manual/scriptable equivalent
  of the "Update now" button (see "Geolocation" above); same `GeoDbUpdater::update()` call as the
  admin triggers and the scheduled job below. Its output (and the scheduled job's log line) reports
  both dates the index carries - "Datenstand" (`sourceDate`, the RIR snapshot's own date) and
  "erstellt" (`builtAt`, when that snapshot was turned into an index file) - matching what both
  admin dashboards already show. **These two dates normally differ by roughly a day and that's
  expected, not a bug:** in `prebuilt` mode the companion repo's nightly CI build always runs some
  hours *after* the RIR snapshot it consumes was published, so `builtAt`'s calendar day is
  consistently one day later than `sourceDate`'s. An earlier version of this command only printed
  `sourceDate`, which made a perfectly normal build look like a one-day mismatch against the
  dashboards' "Erstellt am"/"Built" date at a glance.
- **`bin/plugin page-insights prune --older-than=<value> [--yes] [--vacuum]`** - deletes `data` rows
  (page hits) older than `<value>` and, always, any now-orphaned `events` rows (`Stats::pruneData()`
  calls `pruneOrphanedEvents()` internally after every run - see below). `<value>` is either a short
  relative offset (`90d`/`12w`/`6m`/`1y`) or an absolute date (`2025-01-01`), parsed by
  `RelativeDate::resolve()` - deliberately not free-form `strtotime()`, since this drives an
  irreversible `DELETE`. `--vacuum` runs `VACUUM` immediately afterwards (see `vacuum` below) in the
  same invocation.
- **`bin/plugin page-insights events:prune-orphans`** - just the orphaned-`events` cleanup, without
  any age cutoff. `events.session_id` is declared `REFERENCES data (id)` in the schema but without
  `ON DELETE CASCADE`, and `Stats`'s own connection explicitly runs `PRAGMA foreign_keys = OFF` (see
  "Notable past bugs" below and `DATABASES.md` for the full schema/pragma reasoning) - so deleting a
  `data` row, by any means, never automatically removes its `events`. `prune` already covers rows it
  deletes itself; this command is for cleaning up drift that predates `pruneData()`/
  `pruneOrphanedEvents()` existing at all, without touching any otherwise-current `data`.
- **`bin/plugin page-insights vacuum`** - runs `VACUUM` on its own, independent of `prune`. SQLite
  only frees deleted rows' pages for internal reuse by default; the file itself stays at its
  largest-ever size until `VACUUM` rewrites it. Needs a brief exclusive lock on the database.

## Admin2 database maintenance dialog (`PageInsightsApiController::maintainDb()`)

An on-demand "Maintain database" button sits next to the "Database size: X MB" badge in the
Admin2 dashboard toolbar (`admin-next/pages/page-insights.js`,
`_openDbMaintainDialog()`/`_runDbMaintenance()`). It calls the same `Stats` methods the CLI
commands above already use - no new business logic, purely a UI on top of `vacuum()`/
`pruneOrphanedEvents()`/`pruneData()` via a new endpoint, `POST /page-insights/db/maintain`
(`api.system.write`, same pattern as `rebuildGeoDb()`). Admin2-only, deliberately - no Classic
Admin equivalent. Per "Design goals" above: this is an *actionable* surface (it triggers
irreversible deletes/a VACUUM), not a read-only display like the HTTP status codes widget - it
would mean maintaining a second mutation-triggering surface in the old environment going
forward, which isn't worth it for a feature this specific.

**UI:** a single `window.__GRAV_DIALOGS.form()` modal - a warning that deletion is permanent,
followed by one `select` field with exactly three presets (`vacuum` / `prune_orphans` /
`prune_old`). Deliberately just the one dialog, with no separate `confirm()` safety step
afterwards: the warning is already shown right above the choice, and the modal's own submit
button *is* the confirmation - matching what was actually asked for rather than adding an extra
click "to be safe". Deliberately no free-form "older than" input either (unlike the `prune` CLI
command's `--older-than`) - three fixed presets keep the dialog simple, which was an explicit
design goal for this feature.

**Backend mapping** (`maintainDb()`):

- `vacuum` → `Stats::vacuum()` only. No data deleted.
- `prune_orphans` → `Stats::pruneOrphanedEvents()`, then `Stats::vacuum()`.
- `prune_old` → `Stats::pruneData($cutoff)` with `$cutoff` fixed at "now minus 1 year" (not
  configurable in this dialog - see above), then `Stats::vacuum()`. `pruneData()` already deletes
  orphaned events as a side effect (see its doc comment), so this covers both in one preset.

`VACUUM` always runs last regardless of which preset was chosen, mirroring the CLI's own
`prune --vacuum` combination - the response therefore always reports a `size_before`/`size_after`
pair, and `deleted` is `null` only for the pure-`vacuum` preset (used by the frontend to pick
between the "N row(s) deleted, X MB → Y MB" and plain "X MB → Y MB" toast wording).

A successful run also writes a `grav.log` info line (chosen action, triggering username, rows
deleted, size before/after) - same reasoning and same log destination as the geo-db rebuild's
above, not the `api` plugin's Audit Trail.

## "Hide bots" filter (`PageInsightsApiController::getBotFilter()`)

A toolbar toggle in the Admin2 dashboard (and Page/User Detail views) that filters every KPI,
chart and list to hits not recognized as bot traffic - added after two upstream Page Stats issues
asked for exactly this (`filter/recognise bots or crawlers`, `Iranian bots not filtered out?`).
Admin2-only, like `default_pages_scope` (see "Config blueprint" above) - no Classic Admin
equivalent; this is a read-only display filter, not a mutating action, so per "Design goals" above
it could go there too, but there's no live per-view toggle mechanism on that side to hang it off of
(Classic Admin's own "real pages" scope precedent never got one either).

**Data model:** no schema change. Backed entirely by the existing `data.is_bot` column, populated
since the very first migration by `Stats::collect()`/`Stats::isBot()` from the `bot_regexp` config
list, but never read back by any query until now - see `docs/DATABASES.md` for the column's exact
history and caveats (in short: a best-effort, user-agent-substring classification, not a guarantee;
a bot that doesn't self-identify in its UA, or that spoofs a real browser's UA, is invisible to it).
`getBotFilter()` turns `?hide_bots=1` into the `Stats::query()` equality filter `['is_bot' => 0]` -
no new query method needed, the existing generic filter mechanism (see "Backend: generic query
filter" above) already covers it.

**Scope - deliberately dashboard-wide, unlike `getScopeFilter()`:** the existing "real pages only"
scope filter only ever applies to "Recently viewed pages" (see its own doc comment) - a narrower,
single-card filter was the right call there, since it answers a different question ("which routes
count as real content"). "Hide bots" answers "how many of my visits are actually human", which only
makes sense applied consistently everywhere - `getBotFilter()`'s result is merged into every
endpoint in `PageInsightsApiController` (`overview()`'s KPI totals and every "top" list,
`pages()`/`countries()`/`browsers()`/`platforms()`/`users()`, `pageDetail()`/`userDetail()`,
`recent()`, `summary()`), and the Admin2 dashboard's toggle click handler calls the same full
`_load()` a date-range change would, not the narrower single-card reload `_setRecentScope()` uses
for the pages scope toggle.

**Admin-configurable default** (`default_hide_bots` config field, default `false`/off): same
first-load-adoption pattern as `default_pages_scope` (`PageInsightsApiController::
getDefaultHideBots()`, echoed in the `/overview` response) - defaulting to off so upgrading
installs' dashboard numbers don't silently change. Adopting a default of `true` is more expensive
here than for the pages scope, though: since the filter is dashboard-wide rather than one card, the
dashboard's very first `/overview` request (sent before any config default is known) can't yet
include `hide_bots=1`, so a site with the default turned on pays for a second, full dashboard
reload on first load once the true default arrives - accepted as a rare, self-selected cost (only
sites that opted into the non-default choice hit it), mirroring the same trade-off the pages-scope
default already makes structurally, just paid across the whole dashboard instead of one card.

## Automatic scheduling (`PageInsightsPlugin::onSchedulerInitialized()`, `AutoSchedule`)

Rather than asking the admin to add a plugin-specific crontab line for `geo-db:update`/`prune`,
the plugin hooks Grav core's own `onSchedulerInitialized` event - fired by `Grav\Common\Scheduler\
Scheduler` whenever it actually runs (`bin/grav scheduler`, i.e. the site's single, already-existing
cron entry for Grav's built-in Scheduler; also the Admin's Scheduler status page, or a Scheduler
webhook) - and registers two `Scheduler::addFunction()` jobs directly as PHP closures, executed
in-process by the same `bin/grav scheduler` run (`Job::exec()` calls the closure via
`call_user_func_array()`, no subprocess). This mirrors exactly how Grav core itself schedules cache
purge/clear and backups (`Grav\Common\Cache`/`Grav\Common\Backup\Backups`, same event). Registered
unconditionally in `getSubscribedEvents()` (like `onApiRegisterRoutes` et al.), not inside the
`isAdmin()` branch - `bin/grav scheduler`'s CLI context is neither `isAdmin()` nor a normal
frontend request.

Both jobs are opt-in/opt-out via config, independently:

- `geo_db_auto_update` (`disabled`|`weekly`|`monthly`, **default `weekly`**) - safe to default to
  enabled, it only refreshes a lookup file.
- `data_auto_prune` (`disabled`|`weekly`|`monthly`, **default `disabled`**) plus
  `data_auto_prune_older_than` (default `365d`, same syntax as `prune --older-than`) - default
  *disabled*, unlike the geo-db job: this permanently deletes data, so it's opt-in. Deliberately
  never runs `VACUUM` itself, even though the manual `prune` command offers `--vacuum` for exactly
  that combination - an admin opting into unattended deletion isn't necessarily also opting into an
  unattended brief exclusive database lock; use `vacuum` (optionally its own scheduler/cron entry)
  separately if that's wanted too.

The admin never picks a concrete weekday or time - only `disabled`/`weekly`/`monthly`. `AutoSchedule
::cronExpression()` derives the actual weekday/day-of-month and time-of-day deterministically from
`crc32(GRAV_ROOT . ':' . $jobKey)`. This exists specifically to avoid many independent installations
of this plugin clustering on the same instinctive time (e.g. "Sunday 00:05", or any round hour/
top-of-hour minute - all popular default cron times on shared hosting in their own right) once
there are enough installations for that to matter. `GRAV_ROOT` (not e.g. the request hostname) is
used as the seed because it's the one value that's stable and available in every context this can
run from, including `bin/grav scheduler`'s own CLI context, which has no HTTP host to read at all -
the trade-off is that moving a whole site to a different path/server shifts its computed schedule,
accepted as a rare, harmless side effect. `$jobKey` (`"geo-db-update"` vs. `"data-auto-prune"`)
keeps the two jobs on one site from landing on the exact same second.

`AutoSchedule::nextRun()` computes, from the same seed/jobKey/mode, the next actual occurrence as
a plain `DateTimeImmutable` - deliberately separate from `cronExpression()` (which only the
Scheduler registration needs) so a read-only "next run" display doesn't need a cron-expression
parser, just the same handful of lines of date arithmetic already used to derive the cron fields.
Surfaced in both admin UIs next to the database size (`Stats::dbStats()`'s `next_geo_db_update`/
`next_auto_prune`, `null` when the respective job is "disabled") - Classic Admin's
`stats.html.twig` titlebar and Admin2's dashboard toolbar both already render `Stats::dbStats()`'s
other fields there, so piggy-backing on that one method gets both UIs the schedule info without a
new route or twig variable.

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
correct.** This has already happened once in this project's history (see "Notable past bugs")
and is easy to miss because an existing, already-`vendor/`-populated working copy keeps running
fine on the old autoloader - only a genuinely fresh checkout/clone reveals the mismatch.

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

## Notable past bugs (useful context before touching related code)

1. **`Class "Grav\Plugin\PageInsights\Stats" not found`** on a fresh clone, `composer.json`
   itself correct. Cause: the compiled autoloader still referenced the pre-rename
   `Grav\Plugin\PageStats` namespace from before the Page Stats -> Page Insights rename, and
   `composer dump-autoload` had never been re-run/committed afterwards. See "Composer & the
   compiled autoloader" above.
2. **`str_contains()` without a PHP 8.0 floor.** `composer.json` declared `>=7.1.3` while
   `Stats::query()` unconditionally calls `str_contains()` on every filtered query - a fatal
   error on PHP < 8.0 at the most central, most frequently hit code path in the plugin. Caught by
   checking function *availability*, not just 8.x-only syntax.
3. **CSS gap bug pattern:** a new flex-container class without the same `gap` rule as its sibling
   container. Occurred twice independently (`.charts`/`.body`, later `.detail-body`) - worth
   double-checking on every new `.body`-like container class.
4. Four independent, presumably years-old bugs in the original `Stats.php` date filter (a
   `TypeError` on a missing client IP in CLI contexts, broken `date_from`/`date_to` binding,
   wrong parameter order in `siteSummary()`, a missing `%where` in `pagesSummary()`) - fixed
   during the initial Admin2 migration, kept here as a reminder that the date-filter path had a
   history of subtle bugs.
5. **SQLite under load without `PRAGMA busy_timeout`/WAL** - suspected cause of a server outage.
   Fixed with `busy_timeout = 5000`, `journal_mode = WAL`, `synchronous = NORMAL` (current pragma
   set and the reasoning behind each one: `DATABASES.md`).
6. **Admin2 custom field silently rendered as a plain text input.** The first geo-db rebuild UI
   was a custom Admin2 blueprint field (`admin-next/fields/geodbupdate.js`, since removed). The
   discovery mechanism itself (`{plugin}/admin-next/fields/*.js` auto-registered by
   `grav-plugin-api`'s `GpmController`, served to Admin2 via `GET /custom-fields`) is real and
   correctly implemented - the site it was tested on simply doesn't run `grav-plugin-api` at all
   (Classic Admin only), so the field type was never registered and Admin2 fell back to its
   default text input for an unrecognized type - no error, no console output, just a blank box.
   Worth checking *which* admin UI (and, for Admin2, whether `grav-plugin-api` is installed) is
   actually in use before debugging a "field doesn't render" report any further. Replaced with the
   dashboard-integrated trigger described above, which works without that dependency.
7. **`RirStatsParser::parse()` exhausting a stock 128M `memory_limit`** on the real, tens-of-MB
   RIR source file - only ever tested before against a small hand-built fixture. `preg_split()`
   over the whole file materializes an array holding every line as its own string, on top of the
   full text already being in memory; replaced with a `strtok()`-based loop. Confirmed via a
   synthetic `memory_get_peak_usage()` comparison that the line-splitting change alone isn't
   sufficient headroom for a realistically-sized file - `CountryIndexBuilder::build()` also
   temporarily raises `memory_limit` to 512M for this one call as the change that actually
   matters. A reminder that a hand-built fixture only proves parsing *logic* is correct, not that
   it fits in memory at real scale.
8. **`CountryIndexBuilder::build()`'s own `memory_limit` restore crashed a build that had already
   succeeded.** The `finally` block from bug #7's fix restores `memory_limit` back to its
   pre-build value once the download/parse/build/write pipeline finishes. On a real installation
   this itself turned fatal: `ini_set('memory_limit', ...)` to a value *below* current usage isn't
   a no-op or a warning, it's a catchable `\Error` ("Failed to set memory limit to 134217728 bytes
   (Current memory usage is ~300MB bytes)", `Zend/zend_alloc.c`) - and the parsed/index arrays are
   still referenced (and thus still counted) at the point the `finally` block runs, routinely well
   above a stock 128M default even after a successful build. Because this throws from inside
   `finally`, it replaces the method's already-computed return value, so the admin UI reported
   "Could not update the geo country database" even though the index file had already been written
   to disk. Fixed by only restoring when `memory_get_usage(true)` is safely below the target limit
   (`CountryIndexBuilder::restoreMemoryLimit()`/`parseMemoryLimit()`) - skipping the restore is
   harmless since PHP resets every `ini_set()` change at the end of the request/PHP-FPM worker
   cycle regardless. A reminder that "restore the old value in `finally`" isn't automatically
   safe when the thing you raised is itself a function of how much memory is currently in use.
9. **The old `IP2LOCATION-LITE-DB3.BIN` removed in 2026-08-15 (see Geolocation above) was still
   being served from git history itself.** Removing a file from HEAD via `git rm` only stops it
   from being in future commits - the blob stayed reachable through the `3.0.0`/`3.0.1` tags,
   both of which predate the removal, meaning GitHub's and Codeberg's auto-generated "Source code
   (zip)" downloads for those two tags kept serving the ~47 MB non-redistributable binary (plus a
   second, smaller `IP2LOCATION-LITE-DB1.BIN` bundled as a sample inside
   `vendor/ip2location/ip2location-php/databases/`, easy to miss since it isn't the file anyone
   was looking for) long after the release archives themselves were clean. Fixed 2026-08-16 with
   a full history rewrite (`git filter-repo`, both blob IDs stripped via
   `--strip-blobs-with-ids`) rooted at `a1677c4` ("Initial commit for the fork") as the new
   parentless root - chosen over either discarding history entirely or a plain blob-strip, since
   the blob's first appearance predated the fork itself and a blob-strip alone would have
   rewritten essentially the same range of commits anyway. All commit hashes from that point on
   changed as a result; anyone with an existing clone needs to re-clone rather than pull. A
   reminder that removing a file from the tree and removing it from the repository are not the
   same operation, and that this matters most for anything a public host will happily zip up and
   serve on request.
10. **Admin2 i18n (`9eb3514`) worked in code review and `node --check`, but rendered entirely in
    English on a real Admin2 instance with the admin language set to German.** `window.__GRAV_I18N`
    existed, reported `locale: 'de-DE'` correctly, yet `has('PLUGIN_PAGE_INSIGHTS.TOP_COUNTRIES')`
    was `false` for every plugin key. Root cause, found by reading `grav-plugin-api`'s
    `SystemController::translations()`/`buildTranslationDictionary()` down into Grav core's
    `Config\Languages::flattenByLang()` and `Config\ConfigFileFinder`: the `/translations` endpoint
    looks up strings by the *exact* requested locale code (`de-DE`), a bucket populated only by
    language files literally named `de-DE.yaml` (confirmed empirically - `grav-plugin-admin2`'s own
    `languages/` folder ships only BCP47-coded filenames). This plugin's `languages/de.yaml`
    populates a separate `de` bucket that `/translations` never reads, no matter the admin
    language - a gap invisible to Classic Admin, which resolves the same short-code files through
    the older, separate `Language::translate()` service instead. Fixed without duplicating the
    language files (which would drift out of sync with Weblate) by merging the short-code strings
    into the BCP47 buckets at runtime - see "Admin2 i18n" above and
    `PageInsightsPlugin::mergeAdmin2TranslationAliases()`. A reminder that a bridge existing and
    reporting the right locale doesn't mean the data behind it is actually reachable - worth an
    end-to-end check on a real Admin2 instance with a non-English admin language, not just
    `node --check` and a code read.
11. **The first fix for #10 hooked `mergeAdmin2TranslationAliases()` into `onApiRegisterRoutes()`
    and still showed English after deploying to the real test instance.** Cause: `grav-plugin-api`'s
    `ApiRouter::createDispatcher()` wraps its entire route table in FastRoute's `cachedDispatcher()`,
    backed by `cache://api/route.cache`. Once that cache file exists - which in practice means every
    request after the very first one, since it isn't tied to Grav's own cache-clear at all, only to
    `system.debugger.enabled` - FastRoute deserializes the cached route table directly and never
    re-invokes the route-definition closure, so `onApiRegisterRoutes` (fired from inside that
    closure) simply doesn't run. Any plugin logic riding on that event for a side effect other than
    literally registering routes silently stops firing as soon as the route cache warms up. Fixed by
    moving the call to `onPluginsInitialized()` instead, which Grav core fires unconditionally on
    every request regardless of any plugin's own route/response caching. A reminder that an
    `onApi*` event firing "no-op when the API plugin isn't installed" (true) is not the same
    guarantee as "fires on every request" (false, for the route-registration events specifically) -
    worth checking a plugin event's *caching* behavior, not just whether it fires at all, before
    hanging unrelated logic off it. Deploying the corrected code confirmed a second, separate
    caching layer on top: restarting/reloading the PHP-FPM pool alone was **not** enough to pick up
    the change - an explicit `bin/grav clear-cache` (or the Admin "Clear Cache" action) was required
    before the fix took effect, most likely because of Grav's own compiled-config/language cache
    (`cache://compiled/languages/master-*.php`, see `ConfigServiceProvider::languages()`) combined
    with this environment's APCu-backed cache driver - both persist across a PHP-FPM pool
    reload/restart and are only invalidated by Grav's own cache-clear, not by the webserver/PHP
    process cycling. Worth remembering for *any* plugin PHP change on this environment, not just
    this one: reloading `php8.5-fpm` is not a substitute for `bin/grav clear-cache`.

12. **A freshly-migrated `Stats` connection silently left `PRAGMA foreign_keys` switched on for the
    rest of its own lifetime**, contradicting the class's own documented invariant (see
    `collectEvent()`'s docblock) that foreign keys are never enforced. Found while adding and
    testing `pruneData()`: every shipped `data/migrations/*.sql` file ends with an explicit
    `PRAGMA foreign_keys = on;` - harmless for its original purpose (a standalone script run once
    via a SQLite GUI/CLI tool), but `migrate()` executes that same SQL directly on `$this->db`, so
    on a freshly-installed database (the only time `migrate()` ever runs - `FORCE_MIGRATION_FLAG` is
    deleted at its end) the pragma stayed on afterwards. Never observed in practice on an existing
    install (no code path deletes a `data` row with matching `events` on that specific connection,
    at that specific moment), but `pruneData()` does exactly that. Fixed by having `Stats::__construct()`
    explicitly run `PRAGMA foreign_keys = OFF` right after migration, so the connection's behavior
    never depends on whether `migrate()` just ran. Reproduced and verified fixed against a scratch
    SQLite database (pre-existing + newly-orphaned `events` rows, verified gone after `pruneData()`;
    a second `pruneOrphanedEvents()` call afterwards correctly deletes nothing). Current pragma
    ordering and the `events.session_id` non-enforcement it protects: `DATABASES.md`.
13. **`VACUUM` on a `journal_mode = WAL` connection (`Stats` always runs in WAL, see `__construct()`)
    doesn't immediately shrink the main database file's on-disk size** - `VACUUM`'s rewritten pages
    land in the WAL file first, like any other write, and only get folded back into the main file on
    a checkpoint. Naively `filesize()`-ing the main file immediately before/after `VACUUM` (as
    `vacuum()`'s before/after reporting first did) showed the exact same size both times, on a
    scratch database verified to have genuinely shrunk once the connection was closed (which
    triggers an implicit checkpoint) - i.e. `VACUUM` worked, but the reported numbers falsely
    suggested it hadn't. Fixed by running `PRAGMA wal_checkpoint(TRUNCATE)` explicitly, both right
    before measuring "before" and right after `VACUUM` before measuring "after", so the CLI/scheduler
    output reflects the true, immediate result rather than whatever happens to be checkpointed yet.
    Current behavior, documented as such rather than as a bug report: `DATABASES.md`.

## Known cleanup items

- `classes/Api/PageStatsApiController.php` and `admin-next/pages/page-stats.js` are leftover,
  unreferenced files from before the Page Insights rename (superseded by
  `PageInsightsApiController.php` / `page-insights.js`). Harmless but confusing for new
  contributors - safe to `git rm` whenever convenient.

## Live status (at time of writing)

Version 3.0.0 (unreleased) is the first release under the Page Insights name; see `CHANGELOG.md`
for the current released version and `README.md` for user-facing configuration docs. This file
describes architecture and rationale, not release status - please keep it in sync when the design
changes, but don't duplicate version numbers here.

---

## Auf Deutsch (Kurzfassung)

Diese Datei richtet sich an Contributor, die am Code arbeiten wollen (Endnutzer-Doku steht in
`README.md`). Datenbankschemata (Tabellen/Spalten, Indizes, Pragmas, das Binärformat des
Geo-Country-Index) samt der dahinterstehenden Design-Entscheidungen stehen bewusst separat in
[`DATABASES.md`](DATABASES.md) - diese Datei verlinkt dort hin statt sie zu duplizieren. Kernpunkte
hier: zwei Admin-Oberflächen (Classic Admin, neun Twig-Unterseiten; Admin2,
eine Web-Component) teilen sich dieselbe Datenschicht (`classes/Stats.php`) - Unterschiede
betreffen ausschließlich die Präsentation, nicht Erfassung/Speicherung. Der generische
Spalten-Filter-Mechanismus (`Stats::query()`, `$params`-Array) deckt die meisten neuen
Filter-Anforderungen bereits ab - vor einer neuen, eigens gefilterten Methode immer erst prüfen.

Admin2s Sub-Routing für Page/User Detail läuft bewusst über Query-Parameter auf der festen
Plugin-Route (`?view=page-detail&route=...`), nicht über zusätzliche Pfadsegmente - SvelteKits
Router kennt kein tieferes Segment. Details siehe Abschnitt "Admin2 sub-routing" oben.

Das Admin2-Dashboard (`admin-next/pages/page-insights.js`) übersetzt seine UI-Texte über
`window.__GRAV_I18N` - eine von `grav-admin-next` selbst bereitgestellte, read-only globale
Brücke für genau diesen Zweck (dasselbe Muster wie das bereits genutzte `window.__GRAV_TOAST`),
mit Fallback auf hartcodiertes Englisch, falls die Brücke fehlt oder ein Schlüssel unübersetzt
ist. Details siehe Abschnitt "Admin2 i18n" oben - insbesondere: keine automatische
`%s`-Ersetzung für Plugin-Schlüssel (dafür der eigene `_tf()`-Helfer), und Re-Render bei
Sprachwechsel zur Laufzeit über `subscribe()`.

Wichtigster operativer Stolperstein: `vendor/` ist bewusst committet (Grav installiert
Composer-Abhängigkeiten nie selbst), aber die **kompilierten** Autoloader-Dateien
(`vendor/composer/autoload_*.php`) werden nicht automatisch aus `composer.json` neu erzeugt.
Jede Namespace-/Datei-Umbenennung braucht zwingend `composer dump-autoload` + Commit, sonst droht
`Class "..." not found` bei jedem frischen Checkout - genau das ist bereits einmal passiert (siehe
"Notable past bugs" oben). Ebenso kann `composer.lock`s `content-hash`/`platform-overrides` nach
einer `composer.json`-Änderung veraltet zurückbleiben, wenn `composer update --lock` nicht
nachgezogen wird - lokal mit bereits vorhandenem `vendor/` unauffällig, in einer sauberen
CI-Umgebung aber ein harter Fehlschlag beim `composer install`-Schritt.

Zwei Admin-UI-Blueprint-Eigenheiten: `type: section` braucht in Admin2 zusätzlich ein `title`, um
sichtbar zu werden; `type: display` existiert nur in Admin2 sinnvoll. Gemeinsamer Nenner für
Infoboxen, die auf beiden Admin-Versionen funktionieren sollen: `section` + `title` + `text` +
`fields: {}`.

Neu (siehe "CLI commands" / "Automatic scheduling" oben): vier `bin/plugin page-insights`-Befehle
(`geo-db:update`, `prune`, `events:prune-orphans`, `vacuum`) unter `cli/`, per Gravs eigener
Auto-Discovery erkannt (kein Composer-Eintrag nötig). Die vormals als "Phase 2" zurückgestellte
automatische Geo-DB-Aktualisierung ist damit umgesetzt, ergänzt um ein ebenso optionales,
automatisches Löschen alter Statistikdaten (`data_auto_prune`, standardmäßig deaktiviert - im
Gegensatz zur Geo-DB-Aktualisierung, die standardmäßig aktiv ist, weil unwiderrufliches Löschen
ein bewusstes Opt-in bleiben sollte). Beide hängen sich an Gravs eigenes `onSchedulerInitialized`-
Event (`bin/grav scheduler`) statt einen eigenen Crontab-Eintrag zu verlangen. Wochentag/Tag im
Monat und Uhrzeit sind dabei nicht einstellbar, sondern werden deterministisch aus einem Hash von
`GRAV_ROOT` abgeleitet (`AutoSchedule`) - verhindert, dass viele unabhängige Installationen sich
alle zum selben naheliegenden Zeitpunkt (z. B. "Sonntag 0:05") häufen. Beide Admin-Oberflächen
zeigen inzwischen zusätzlich neben der Datenbankgröße an, wann der jeweilige Job als Nächstes
läuft (`AutoSchedule::nextRun()`, über `Stats::dbStats()` mitgeliefert, entfällt bei "deaktiviert").
