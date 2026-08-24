# Maintenance

This document describes the plugin's database upkeep surfaces: the CLI commands, the Admin2
"Maintain database" dialog, and the automatic scheduler jobs that can run some of the same
operations unattended. It does **not** cover the underlying `Stats` methods' SQL or the schema
they operate on (see [`DATABASES.md`](DATABASES.md)) or the geo-index rebuild, which has its own
CLI/scheduler/admin paths documented in [`GEOLOCATION.md`](GEOLOCATION.md).
*(Eine deutsche Kurzfassung findest du am Ende dieser Datei.)*

## CLI commands (`cli/`)

Grav auto-discovers any `Grav\Plugin\Console\<Name>Command` class in a plugin's
`cli/<Name>Command.php` (see Grav core's `PluginCommandLoader`) - no registration in
`composer.json` or anywhere else is needed, and `Symfony\Component\Console\*` comes from Grav
core's own vendor tree, not this plugin's (same reasoning as relying on Grav core's Scheduler
classes, see below - avoids repeating the vendor-bloat mistake git history already went through
once, see [`HISTORY.md`](HISTORY.md) #9/#12).

- **`bin/plugin page-insights geo-db:update [--mode=prebuilt|raw]`** - manual/scriptable
  equivalent of the "Update now" button (see [`GEOLOCATION.md`](GEOLOCATION.md)); same
  `GeoDbUpdater::update()` call as the admin triggers and the scheduled job below. Its output (and
  the scheduled job's log line) reports both dates the index carries - "Datenstand" (`sourceDate`,
  the RIR snapshot's own date) and "erstellt" (`builtAt`, when that snapshot was turned into an
  index file) - matching what both admin dashboards already show. **These two dates normally
  differ by roughly a day and that's expected, not a bug:** in `prebuilt` mode the companion
  repo's nightly CI build always runs some hours *after* the RIR snapshot it consumes was
  published, so `builtAt`'s calendar day is consistently one day later than `sourceDate`'s. An
  earlier version of this command only printed `sourceDate`, which made a perfectly normal build
  look like a one-day mismatch against the dashboards' "Erstellt am"/"Built" date at a glance.
- **`bin/plugin page-insights prune --older-than=<value> [--yes] [--vacuum]`** - deletes `data`
  rows (page hits) older than `<value>` and, always, any now-orphaned `events` rows
  (`Stats::pruneData()` calls `pruneOrphanedEvents()` internally after every run - see below).
  `<value>` is either a short relative offset (`90d`/`12w`/`6m`/`1y`) or an absolute date
  (`2025-01-01`), parsed by `RelativeDate::resolve()` - deliberately not free-form `strtotime()`,
  since this drives an irreversible `DELETE`. `--vacuum` runs `VACUUM` immediately afterwards (see
  `vacuum` below) in the same invocation.
- **`bin/plugin page-insights events:prune-orphans`** - just the orphaned-`events` cleanup,
  without any age cutoff. `events.session_id` is declared `REFERENCES data (id)` in the schema but
  without `ON DELETE CASCADE`, and `Stats`'s own connection explicitly runs
  `PRAGMA foreign_keys = OFF` (see [`HISTORY.md`](HISTORY.md) and `DATABASES.md` for the full
  schema/pragma reasoning) - so deleting a `data` row, by any means, never automatically removes
  its `events`. `prune` already covers rows it deletes itself; this command is for cleaning up
  drift that predates `pruneData()`/`pruneOrphanedEvents()` existing at all, without touching any
  otherwise-current `data`.
- **`bin/plugin page-insights vacuum`** - runs `VACUUM` on its own, independent of `prune`. SQLite
  only frees deleted rows' pages for internal reuse by default; the file itself stays at its
  largest-ever size until `VACUUM` rewrites it. Needs a brief exclusive lock on the database.
- **`bin/plugin page-insights rollup:build [--date=<day>] [--from=<value> [--to=<value>]]`** -
  (re)computes `rollup_daily`/`rollup_route`/`rollup_country`/`rollup_browser`/`rollup_platform`
  (see `DATABASES.md`, "Rollups") for one or more completed days via `Stats::rollupDay()`;
  idempotent, safe to rerun for any day. Without any option, only catches up whatever's missing
  since the last run (up to yesterday) - deliberately just "yesterday" on a fresh install with no
  prior rollup state, not the entire history, so a bare invocation can't accidentally trigger a
  long-running backfill. Use `--from=<value>` (same relative/absolute syntax as
  `prune --older-than`, via `RelativeDate`) once, manually, to backfill an existing installation's
  history.
- **`bin/plugin page-insights prune:bots [--yes] [--vacuum]`** - deletes every `data` row with
  `is_bot = 1`, regardless of age, plus any now-orphaned `events` rows
  (`Stats::pruneBotTraffic()` calls `pruneOrphanedEvents()` internally, same as `pruneData()`).
  CLI equivalent of the Admin2 maintenance dialog's `prune_bots` preset (see below). Kept as its
  own command rather than an `--older-than`-style option on `prune`, since "is this row a bot" and
  "is this row old" are two unrelated deletion criteria - folding both into one flag would be
  confusing.
- **`bin/plugin page-insights prune:notfound [--yes] [--vacuum]`** - deletes every `data` row with
  `http_code = 404`, regardless of age, plus any now-orphaned `events` rows
  (`Stats::pruneNotFoundHits()`, same `pruneOrphanedEvents()` pattern as above). CLI equivalent of
  the Admin2 maintenance dialog's `prune_notfound` preset. Same reasoning as `prune:bots` for
  keeping it a separate command rather than an `--older-than` variant. **Interaction with scan
  detection (see below):** this deletes the exact `data` rows scan detection's own history is
  read from (`http_code = 404`) - `scan_alerts` rows already raised are untouched (they're a
  separate table, already-derived state), but running this manually removes the underlying
  evidence for any *new* detection going forward until fresh 404s accumulate again. Not a reason
  to avoid `prune:notfound` - just worth knowing before running it on a site with scan detection
  enabled.
- **`bin/plugin page-insights scan-patterns:import [--file=<pfad>] [--source=<name>]`** - see
  "Scan detection" below.

## Admin2 database maintenance dialog (`PageInsightsApiController::maintainDb()`)

An on-demand "Maintain database" button sits next to the "Database size: X MB" badge in the
Admin2 dashboard toolbar (`admin-next/pages/page-insights.js`,
`_openDbMaintainDialog()`/`_runDbMaintenance()`). It calls the same `Stats` methods the CLI
commands above already use - no new business logic, purely a UI on top of `vacuum()`/
`pruneOrphanedEvents()`/`pruneData()` via a new endpoint, `POST /page-insights/db/maintain`
(`api.system.write`, same pattern as `rebuildGeoDb()`). Admin2-only, deliberately - no Classic
Admin equivalent. Per "Design goals" in `ARCHITECTURE.md`: this is an *actionable* surface (it
triggers irreversible deletes/a VACUUM), not a read-only display like the HTTP status codes widget
- it would mean maintaining a second mutation-triggering surface in the old environment going
forward, which isn't worth it for a feature this specific.

**UI:** a single `window.__GRAV_DIALOGS.form()` modal - a warning that deletion is permanent,
followed by one `select` field with five presets (`vacuum` / `prune_orphans` / `prune_old` /
`prune_bots` / `prune_notfound`). Deliberately just the one dialog, with no separate `confirm()`
safety step afterwards: the warning is already shown right above the choice, and the modal's own
submit button *is* the confirmation - matching what was actually asked for rather than adding an
extra click "to be safe". Deliberately no free-form "older than" input either (unlike the `prune`
CLI command's `--older-than`) - fixed presets keep the dialog simple, which was an explicit design
goal for this feature; `prune_bots`/`prune_notfound` fit the same fixed-preset shape as the
original three (no parameter to fill in - "bot" and "404" are both binary conditions), which is
why they were added as presets here rather than as a new kind of dialog control.

**Backend mapping** (`maintainDb()`):

- `vacuum` → `Stats::vacuum()` only. No data deleted.
- `prune_orphans` → `Stats::pruneOrphanedEvents()`, then `Stats::vacuum()`.
- `prune_old` → `Stats::pruneData($cutoff)` with `$cutoff` fixed at "now minus 1 year" (not
  configurable in this dialog - see above), then `Stats::vacuum()`. `pruneData()` already deletes
  orphaned events as a side effect (see its doc comment), so this covers both in one preset.
- `prune_bots` → `Stats::pruneBotTraffic()`, then `Stats::vacuum()`. Deletes every `data` row with
  `is_bot = 1`, regardless of age. Same backend method as the `prune:bots` CLI command above.
- `prune_notfound` → `Stats::pruneNotFoundHits()`, then `Stats::vacuum()`. Deletes every `data`
  row with `http_code = 404`, regardless of age. Same backend method as the `prune:notfound` CLI
  command above.

`VACUUM` always runs last regardless of which preset was chosen, mirroring the CLI's own
`prune --vacuum` combination - the response therefore always reports a `size_before`/`size_after`
pair, and `deleted` is `null` only for the pure-`vacuum` preset (used by the frontend to pick
between the "N row(s) deleted, X MB → Y MB" and plain "X MB → Y MB" toast wording).

A successful run also writes a `grav.log` info line (chosen action, triggering username, rows
deleted, size before/after) - same reasoning and same log destination as the geo-db rebuild's (see
`GEOLOCATION.md`), not the `api` plugin's Audit Trail.

## Automatic scheduling (`PageInsightsPlugin::onSchedulerInitialized()`, `AutoSchedule`)

Rather than asking the admin to add a plugin-specific crontab line for `geo-db:update`/`prune`,
the plugin hooks Grav core's own `onSchedulerInitialized` event - fired by
`Grav\Common\Scheduler\Scheduler` whenever it actually runs (`bin/grav scheduler`, i.e. the site's
single, already-existing cron entry for Grav's built-in Scheduler; also the Admin's Scheduler
status page, or a Scheduler webhook) - and registers two `Scheduler::addFunction()` jobs directly
as PHP closures, executed in-process by the same `bin/grav scheduler` run (`Job::exec()` calls the
closure via `call_user_func_array()`, no subprocess). This mirrors exactly how Grav core itself
schedules cache purge/clear and backups (`Grav\Common\Cache`/`Grav\Common\Backup\Backups`, same
event). Registered unconditionally in `getSubscribedEvents()` (like `onApiRegisterRoutes` et al.),
not inside the `isAdmin()` branch - `bin/grav scheduler`'s CLI context is neither `isAdmin()` nor a
normal frontend request.

**`Job::exec()` already catches `\RuntimeException` thrown from the closure** - the same core
mechanism Grav uses for its own scheduled cache/backup jobs. `GeoDbUpdater::update()`'s own
exception (deliberately left uncaught inside that method - see `GEOLOCATION.md`) is therefore
automatically caught at the Scheduler layer once it's registered as a job here, with no additional
try/catch needed in either `registerGeoDbAutoUpdateJob()` or `registerAutoPruneJob()`. Worth
knowing before adding a fourth scheduled job of this kind: only `\RuntimeException` specifically is
caught this way, not `\Exception`/`\Error` in general, so a method meant to fail safely inside a
scheduler job needs to actually throw that class (or a subclass of it).

All three jobs are opt-in/opt-out via config, independently:

- `geo_db_auto_update` (`disabled`|`weekly`|`monthly`, **default `weekly`**) - safe to default to
  enabled, it only refreshes a lookup file.
- `data_auto_prune` (`disabled`|`weekly`|`monthly`, **default `disabled`**) plus
  `data_auto_prune_older_than` (default `365d`, same syntax as `prune --older-than`) - default
  *disabled*, unlike the geo-db job: this permanently deletes data, so it's opt-in. Deliberately
  never runs `VACUUM` itself, even though the manual `prune` command offers `--vacuum` for exactly
  that combination - an admin opting into unattended deletion isn't necessarily also opting into an
  unattended brief exclusive database lock; use `vacuum` (optionally its own scheduler/cron entry)
  separately if that's wanted too.
- `rollup_auto_build` (`disabled`|`daily`, **default `disabled`**, see `DATABASES.md` "Rollups") -
  runs `Stats::rollupDay()` for whatever's missing since the last run, up to yesterday. Default
  *disabled*: only worth turning on once a site's accumulated traffic/history actually makes the
  dashboard noticeably slow (small/new sites don't need it, and turning it on doesn't retroactively
  backfill history - see `rollup:build --from=...`). Only offers `daily`, not `weekly`/`monthly`
  like the other two jobs - a rollup that falls a week behind defeats its own purpose, since
  `pagesSummary()`'s rollup fast path just falls back to the original live query for whatever isn't
  covered yet.

**Bot-/404-pruning is deliberately *not* a fourth scheduled job.** `prune:bots`/`prune:notfound`
(CLI and dialog preset, above) stay manual-only - there is no `bot_auto_prune`/
`notfound_auto_prune` config field or scheduler registration for either, unlike the age-based
`data_auto_prune`. Reasoning, confirmed explicitly with the plugin author when the two commands
were added: for age-based deletion, "this is allowed to disappear automatically eventually" is an
intuitive default once an admin has set a retention period at all; for bot/404 traffic, the same
isn't as obvious without an admin consciously choosing to look at what's being classified before
letting it be deleted unattended. If unattended bot-/404-pruning is wanted later, an optional
fourth scheduler job (mirroring `data_auto_prune`'s own opt-in config field) would be a cleanly
separable feature to add, not a retrofit of the existing three.

The admin never picks a concrete weekday or time - only `disabled`/`daily`/`weekly`/`monthly`
(`daily` only actually offered for `rollup_auto_build`, see above - `AutoSchedule` itself supports
it generically, nothing stops another job from using it later). `AutoSchedule::cronExpression()`
derives the actual weekday/day-of-month and time-of-day deterministically from
`crc32(GRAV_ROOT . ':' . $jobKey)`. This exists specifically to avoid many independent installations
of this plugin clustering on the same instinctive time (e.g. "Sunday 00:05", or any round hour/
top-of-hour minute - all popular default cron times on shared hosting in their own right) once
there are enough installations for that to matter. `GRAV_ROOT` (not e.g. the request hostname) is
used as the seed because it's the one value that's stable and available in every context this can
run from, including `bin/grav scheduler`'s own CLI context, which has no HTTP host to read at all -
the trade-off is that moving a whole site to a different path/server shifts its computed schedule,
accepted as a rare, harmless side effect. `$jobKey` (`"geo-db-update"` vs. `"data-auto-prune"` vs.
`"rollup-build"`) keeps the jobs on one site from landing on the exact same second.

**Not yet done, deliberately out of scope for this pass:** unlike `next_geo_db_update`/
`next_auto_prune` below, there's no `next_rollup_build`/"next run" display wired into
`Stats::dbStats()` or either admin UI yet for this third job - would follow the exact same
piggy-backing pattern once someone wants it, just not bundled into the performance work that
motivated `rollup_auto_build` itself.

`AutoSchedule::nextRun()` computes, from the same seed/jobKey/mode, the next actual occurrence as
a plain `DateTimeImmutable` - deliberately separate from `cronExpression()` (which only the
Scheduler registration needs) so a read-only "next run" display doesn't need a cron-expression
parser, just the same handful of lines of date arithmetic already used to derive the cron fields.
Surfaced in both admin UIs next to the database size (`Stats::dbStats()`'s `next_geo_db_update`/
`next_auto_prune`, `null` when the respective job is "disabled") - Classic Admin's
`stats.html.twig` titlebar and Admin2's dashboard toolbar both already render `Stats::dbStats()`'s
other fields there, so piggy-backing on that one method gets both UIs the schedule info without a
new route or twig variable.

## Scan detection (`PageInsightsPlugin::registerScanDetectionJob()`, Admin2-only)

See [`ARCHITECTURE.md`](ARCHITECTURE.md) "Scan detection" for the feature's full design/rationale
and [`DATABASES.md`](DATABASES.md) "Tables `scan_patterns` / `scan_alerts`" for the schema; this
section covers the operational surfaces.

**Populating the pattern list** (`scan_patterns` starts empty on every install):

- **`bin/plugin page-insights scan-patterns:import [--file=<pfad>] [--source=<name>]`** - without
  `--file`, imports the plugin's own bundled `data/scan-patterns-webexploits.txt` snapshot (see
  that file's header for provenance/license); with `--file`, imports a custom list in the same
  one-pattern-per-line format instead. Always insert-only-if-missing (`Stats::importScanPatterns()`)
  - safe to re-run after a future release ships an updated snapshot, or repeatedly against the
  same file; existing rows (including ones an admin has since disabled or deleted) are never
  touched or re-created.
- **Admin2 "Scan detection" view** (sidebar link next to "Maintain database" on the dashboard,
  `admin-next/pages/page-insights.js`) - lists every pattern with an enable/disable toggle and a
  delete button, plus a one-line "add pattern" form (inserted with `source = 'manual'`, same
  insert-only-if-missing behaviour as the CLI import). Also lists currently open `scan_alerts`.
  No Classic Admin equivalent - an actionable, mutation-triggering surface, same category as the
  Admin2-only database maintenance dialog above (see "Design goals" in `ARCHITECTURE.md`).

**The detection job itself** - opt-in (`scan_detection`, default `false`), registered from
`onSchedulerInitialized()` alongside the three jobs above but, unlike them, **not** built on
`AutoSchedule`: that class only ever derives a `disabled`/`daily`/`weekly`/`monthly` point in
time, since none of its other callers needed a sub-daily interval. `registerScanDetectionJob()`
uses a fixed `*/5 * * * *` cron expression instead - the site's one `bin/grav scheduler` cron
entry already runs every minute regardless, so this needs no separate crontab line or admin setup
beyond the `scan_detection` toggle itself.

Each run calls `Stats::detectScans($windowMinutes, $threshold)` (config: `scan_detection_window_minutes`
default 10, `scan_detection_threshold` default 5) and logs a summary line per matched IP to
`logs/page-insights-scan-detection.out` (same `Job::output()` convention as the other three jobs).
If `scan_detection_alert_email` is set, the job also calls `Job::email()` - Grav-Core's own
`Scheduler\Job` email support (the same mechanism the Admin's "custom scheduled jobs" UI exposes
as its own "E-Mail" field), which internally no-ops unless the separate, official `email` plugin
(`bin/gpm install email`) is installed and configured. Only sent once per alert
(`scan_alerts.notified_at`), not on every run for as long as an incident continues - see
`ARCHITECTURE.md` for the full alerting design, including the independent, always-live Admin2
dashboard banner (`onApiDashboardNotifications`).

---

## Auf Deutsch (Kurzfassung)

Diese Datei beschreibt die Wartungsoberflächen: CLI-Befehle, den Admin2-Wartungsdialog und die
automatischen Scheduler-Jobs. Die zugrundeliegenden `Stats`-Methoden/das Schema stehen in
`DATABASES.md`, der Geo-Index-Rebuild in `GEOLOCATION.md`.

**CLI-Befehle** (`cli/`, automatisch von Grav über `PluginCommandLoader` erkannt):
`geo-db:update`, `prune --older-than=<Wert> [--vacuum]`, `events:prune-orphans`, `vacuum`,
`rollup:build [--from=...]`, `prune:bots`, `prune:notfound` - letztere beide löschen unabhängig
vom Alter (Bot- bzw. 404-Kriterium statt Alterskriterium).

**Admin2-Wartungsdialog** (`maintainDb()`): ein einzelner Dialog mit fünf Presets
(`vacuum`/`prune_orphans`/`prune_old`/`prune_bots`/`prune_notfound`), jeweils auf dieselben
`Stats`-Methoden wie die CLI-Befehle abgebildet, `VACUUM` läuft danach immer.

**Automatische Scheduler-Jobs** (`onSchedulerInitialized()`, `AutoSchedule`): drei unabhängig
zu-/abschaltbare Jobs (`geo_db_auto_update` Standard an, `data_auto_prune`/`rollup_auto_build`
Standard aus) laufen als PHP-Closures im selben `bin/grav scheduler`-Aufruf mit, ohne eigenen
Cron-Eintrag. `Job::exec()` fängt dabei bereits `\RuntimeException` um den Closure-Aufruf ab -
`GeoDbUpdater::update()`s bewusst uncaught gelassene Exception wird dadurch automatisch
abgefangen, ohne zusätzliches try/catch im Scheduler-Hook. Bot-/404-Pruning ist bewusst **kein**
vierter Scheduler-Job - anders als bei Alters-basiertem Löschen ist bei Bot-/404-Traffic weniger
offensichtlich, dass unbeaufsichtigtes Löschen ohne bewusstes Hinschauen gewollt ist; bei Bedarf
wäre ein optionaler vierter Job (analog `data_auto_prune`) ein sauber abgrenzbares eigenes Feature.
Konkreter Wochentag/Uhrzeit wird nie vom Admin gewählt, sondern deterministisch aus
`crc32(GRAV_ROOT . jobKey)` abgeleitet, um eine Häufung vieler Installationen auf denselben
Standard-Cron-Zeitpunkt zu vermeiden.
