# Geolocation

This document describes the plugin's country-lookup subsystem (`classes/Geolocation/`): where the
data comes from, how the on-disk index is built and read, and the design decisions behind both. It
does **not** cover the admin UI surfaces that trigger a rebuild (see "Geo country index rebuild" in
[`ADMIN-UI.md`](ADMIN-UI.md)) or the on-disk binary format's exact byte layout (see "Geo country
index" in [`DATABASES.md`](DATABASES.md)) - this file links to both rather than duplicating them.
*(Eine deutsche Kurzfassung findest du am Ende dieser Datei.)*

## Background: country-only, self-built, never shipped in the repo

Until 2026-08-15 this wrapped the `ip2location/ip2location-php` Composer package around a committed
`data/IP2LOCATION-LITE-DB3.BIN` (country+region+city). That file alone was ~47 MB - over 90% of the
plugin's total checkout size, and shipped in every GitHub release archive since
`release-from-tag.yml` just archives the tagged tree. Worse, IP2Location LITE's own terms prohibit
exactly that ("third party database repository" redistribution is explicitly disallowed - only a
per-user, individually registered download is permitted). Investigating the fix surfaced two more
findings that changed the design rather than just the data source: (1) `region`/`city` were written
to the stats DB on every hit but never read back anywhere - no query, no admin UI - only
`countryCode` was ever used; (2) *any* snapshot committed once per plugin release goes stale between
releases regardless of vendor or license, which a fixed-file model can't fix at all. (Addendum,
2026-08-16: the removed binary was still reachable via git history through the `3.0.0`/`3.0.1` tags
- see [`HISTORY.md`](HISTORY.md) #9.)

The replacement (`RirStatsParser`, `CountryIndexBuilder`, `CountryLookup`) is built from the
combined **RIR delegated-stats** file - the same public, daily-updated ground-truth allocation data
(RIPE NCC/ARIN/APNIC/LACNIC/AFRINIC via the NRO) that commercial GeoIP vendors themselves build on
top of, published free of any license/token/account gate at
`https://ftp.ripe.net/pub/stats/ripencc/nro-stats/latest/nro-delegated-stats` (format spec:
`https://ftp.ripe.net/ripe/stats/RIR-Statistics-Exchange-Format.txt`). Only country-level data
exists in this source in the first place, which matches finding (1) above - `GeolocationData` keeps
its `countryName()`/`region()`/`city()` accessors (so `Stats.php`'s existing column bindings don't
need a schema migration) but they now just return `'unknown'`; only `countryCode()` carries real
data.

## The three classes

- **`RirStatsParser`** - pure text-in/ranges-out. Parses the pipe-delimited format, keeps only
  `type=ipv4|ipv6` records with `status=allocated|assigned` (skips `available`/`reserved`/`asn`),
  normalizes IPv4 ranges to `[startInt, endInt]` and IPv6 ranges to `[start16ByteString,
  end16ByteString]` (host bits set from the CIDR prefix length). No HTTP, no file I/O - kept
  independently testable against a small in-memory fixture instead of the real ~20-30 MB file.
- **`CountryIndexBuilder`** - fetches the source URL (curl if available, `file_get_contents`
  fallback), sorts ranges per IP version, and **gap-fills**: every possible address must resolve to
  exactly one entry, so unallocated/reserved holes between real ranges get an explicit `UNKNOWN_CC`
  ("ZZ", ISO 3166-1's reserved "unknown country" code) entry rather than being left out - that's
  what lets `CountryLookup` always do a plain "greatest start <= address" binary search with no
  separate end-of-range check. Adjacent same-country entries are merged. Writes its own small, fully
  self-documented binary format (see `DATABASES.md` for the exact byte layout and the design
  decisions behind it, also mirrored in the class doc comment) - deliberately not IP2Location's BIN
  format, nothing left to reverse-engineer.
- **`CountryLookup`** - the read side, instantiated fresh on every single page hit
  (`PageInsightsPlugin::collectPageData()`). Binary search directly against the file via
  `fseek`/`fread` per probe (nothing loaded into memory up front, same approach the old IP2Location
  library used) - construction and lookup both degrade to a no-op/`null` if the index file doesn't
  exist yet or is corrupt, never throwing. A missing or stale geo database must never break page
  collection.

`Ip::toNumber()`/`toIP()` (IPv4 <-> integer helpers, previously dead code kept only for a doc
mention) are now actually used by `CountryLookup`'s IPv4 binary search.

## Building the index

Building the index is never automatic - not at install time, not on the page-request path, not on a
timer by itself (see "Automatic scheduling" in [`MAINTENANCE.md`](MAINTENANCE.md) for the opt-in
scheduled job). It's an explicit admin action, triggered next to the "Top countries" stat in both
admin UIs rather than from the config form (it's an action tied to that stat, not a setting -
`section_geolocation` in `blueprints.yaml` only holds the source configuration and, since 2026-08-17,
the *destination* path (`geo_db_index_path`, see "File layout" in `ARCHITECTURE.md`) - where to
write the result, as opposed to `geo_db_source_mode`/`geo_db_prebuilt_url`/`geo_db_source_url`
below, which control where the build reads from). Both admin surfaces, and the CLI/scheduler path,
are documented in [`ADMIN-UI.md`](ADMIN-UI.md) and [`MAINTENANCE.md`](MAINTENANCE.md) respectively -
all of them call `GeoDbUpdater::update()`, so there is no third, separate implementation of the
rebuild itself.

Both a successful rebuild and a failure write a `grav.log` line (`addInfo()`/`addError()`
respectively, including the triggering admin's username) - visible via Admin2's Tools -> Logs ("Grav
System Log") without needing DB/API access, so an admin can ask a bug reporter to check the log
rather than reproduce the issue themselves. Deliberately just `grav.log`, not the `api` plugin's
separate Audit Trail (`classes/Api/Audit/` there, off by default) - that system only captures HTTP
requests through its own router, so it can't see the scheduled/CLI-triggered path anyway, and would
add a dependency on that plugin's internal (undocumented) classes for a need `addInfo()` already
covers.

## Two update modes: prebuilt (default) vs. raw RIR build (`GeoDbUpdater`)

As of 2026-08, rebuilding the index has two modes, selected via the `geo_db_source_mode` config
field and dispatched by the `GeoDbUpdater` class (both admin surfaces above call this instead of
`CountryIndexBuilder` directly):

- **`prebuilt` (default):** `CountryIndexBuilder::fetchPrebuilt()` downloads an *already-built*
  index from a small **companion repository** (`page-insights-geo-db`, separate from this plugin's
  repo, at `github.com/chrisschm/page-insights-geo-db`) whose only job is running a scheduled GitHub
  Actions workflow that checks out this repo's `classes/Geolocation` classes unchanged, runs the
  exact same `build()` pipeline described above, and publishes the result as a rolling `latest`
  GitHub Release asset. Consuming sites just download that asset (~3 MB in practice, not the ~54 MB
  raw RIR snapshot) and validate+install it (`CountryIndexBuilder::parseHeader()` checks the magic
  bytes and that the declared entry counts actually match the downloaded byte count, so a
  truncated/corrupt download is rejected *before* it overwrites a previously working index) - no
  parsing, no sorting, and therefore no elevated `memory_limit` requirement on the site itself. This
  exists because the two costs of building locally on every site - a large recurring download on top
  of shared-hosting traffic budgets, and the PHP-array memory overhead of parsing several hundred
  thousand individual RIR records (see [`HISTORY.md`](HISTORY.md) #7) - only need to be paid once
  centrally, on a CI runner with far more headroom than typical shared PHP hosting, rather than once
  per installation.

  **The companion repository must stay public.** `CountryIndexBuilder::fetch()` downloads the
  release asset with a plain unauthenticated HTTPS GET - a private GitHub repo would return the
  asset only to an authenticated request, which would mean shipping a GitHub access token inside the
  plugin (and every consuming site making authenticated requests against it), a dependency
  deliberately avoided. The trade-off this accepts: the built index (and the workflow that builds
  it) is visible to anyone, not just to installations of this plugin - acceptable, since the index
  is derived entirely from already-public RIR data and carries no secret or per-site information.
- **`raw` (opt-out):** `CountryIndexBuilder::build()`, unchanged from the original design above -
  downloads the full RIR snapshot and builds locally. Kept as an explicit, fully-documented escape
  hatch for anyone who doesn't want the companion repo as a middleman: it's a small commercial-free
  registry-run companion project, but "trust the plugin author's separate repo/CI pipeline, not just
  the plugin's own code" is still a real trust decision, and this mode means nobody is forced into
  it. Also the fallback if the companion project is ever unreachable, discontinued, or simply not
  preferred.

  Both modes have their own optional source-URL override field (`geo_db_prebuilt_url` /
  `geo_db_source_url` respectively) - empty uses `CountryIndexBuilder::DEFAULT_PREBUILT_URL` /
  `DEFAULT_SOURCE_URL`. Both fields are always shown in both admin UIs regardless of the selected
  mode (rather than conditionally hidden/shown) - deliberately, after the custom-Admin2-field
  fallback lesson (see [`HISTORY.md`](HISTORY.md) #6): the simplest, most reliably-identical-across-
  both-admin-UIs form beats a cleverer one.

The previously-deferred "scheduler-friendly console command for unattended per-site refresh" is now
`cli/GeoDbUpdateCommand.php` plus, for fully unattended operation with no crontab line of its own,
the automatic job registered by `PageInsightsPlugin::onSchedulerInitialized()` - see "CLI commands"
and "Automatic scheduling" in [`MAINTENANCE.md`](MAINTENANCE.md). Both call `GeoDbUpdater::update()`,
so they pick up either source mode identically to the two manual admin triggers above.

---

## Auf Deutsch (Kurzfassung)

Diese Datei beschreibt das Geolocation-Subsystem (`classes/Geolocation/`): Datenquelle, Aufbau und
Lesen des Index sowie die Design-Entscheidungen dahinter. Die Admin-UI-Auslöser stehen in
`ADMIN-UI.md`, das exakte Binärformat in `DATABASES.md` - beide werden von hier aus verlinkt statt
dupliziert.

Bis 2026-08-15 nutzte das Plugin eine committete IP2Location-LITE-Binärdatei (~47 MB, über 90% der
Repo-Größe) - lizenzrechtlich unzulässig (Weiterverbreitung untersagt) und ohnehin nur für den
Country-Code tatsächlich genutzt (Region/Stadt wurden geschrieben, aber nie gelesen). Ersatz: ein
selbstgebauter Index (`RirStatsParser`/`CountryIndexBuilder`/`CountryLookup`) aus den öffentlichen,
täglich aktualisierten RIR-Delegated-Stats-Daten (RIPE/ARIN/APNIC/LACNIC/AFRINIC via NRO) - nur
Country-Code, kein Region/Stadt-Ersatz.

Der Index wird nie automatisch beim Seitenaufruf gebaut, sondern explizit ausgelöst (Admin-UI oder
CLI/Scheduler, siehe `ADMIN-UI.md`/`MAINTENANCE.md`) - immer über `GeoDbUpdater::update()`. Zwei
Modi: **`prebuilt`** (Standard) lädt einen bereits fertig gebauten, kompakten Index (~3 MB statt
~54 MB) von einem kleinen Companion-Repo (`page-insights-geo-db`), dessen GitHub-Actions-Workflow
zentral einmal pro Woche baut statt pro Website - spart Traffic und den erhöhten `memory_limit`-
Bedarf des lokalen Baus. Das Companion-Repo **muss öffentlich bleiben**, weil der Download
unauthentifiziert per HTTPS-GET erfolgt - ein privates Repo hätte einen Access-Token im Plugin
nötig gemacht, was bewusst vermieden wurde. **`raw`** baut wie ursprünglich lokal aus den RIR-
Rohdaten - als Fluchtoption für alle, die dem Companion-Repo nicht vertrauen wollen oder es
umgehen möchten.
