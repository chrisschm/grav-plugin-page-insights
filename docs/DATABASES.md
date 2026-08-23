# Databases

This document describes the two on-disk data stores the plugin owns: their schema, and the
design decisions behind that schema (indexes, pragmas, on-disk formats) where they matter for
correctness or performance. It does **not** cover coding conventions, admin-UI architecture, or
workflow decisions - those stay in [`ARCHITECTURE.md`](ARCHITECTURE.md). If you're about to add or
change a table, column, index, or the geo-index binary format, this is the file to update;
`ARCHITECTURE.md` only links here rather than duplicating schema details.

Both stores live outside `user/plugins/page-insights/` (in `user/data/page-insights/`) so a GPM
update - which replaces the entire plugin directory - never touches them; see "File layout" in
`ARCHITECTURE.md` for that reasoning and the exact config keys.

## Stats database (`db` config key, default `user/data/page-insights.sqlite`)

SQLite, accessed exclusively through `classes/Stats.php` via PDO. One connection per request/CLI
invocation - the plugin never pools or shares connections across requests.

### Connection setup (`Stats::__construct()`)

Three pragmas are set on every connection, in this order, before any query runs:

| Pragma | Value | Why |
|---|---|---|
| `busy_timeout` | `5000` | Default is `0`, i.e. "database is locked" fails instantly on any write contention. Under real traffic (concurrent page views, each doing an `INSERT`), that can pile up PHP-FPM workers all failing at once. |
| `journal_mode` | `WAL` | Allows one writer + many concurrent readers instead of serializing everything on a single file lock - the plugin's normal access pattern (frequent single-row `INSERT`s from page hits, occasional read-heavy admin dashboard queries). |
| `synchronous` | `NORMAL` | The safe pairing with WAL (`FULL` is unnecessary overhead under WAL; `OFF` would risk corruption). |

Immediately after that, and after running any pending migration (see below), the connection
explicitly runs `PRAGMA foreign_keys = OFF`. This is deliberate, not an oversight: `events.session_id`
is declared `REFERENCES data (id)` in the schema (see below), but the plugin relies on that
reference being documentation-only, never enforced - `pruneData()`/`pruneOrphanedEvents()` delete
`data` rows that `events` rows may still point at, by design, and clean up the resulting orphans
separately rather than relying on `ON DELETE CASCADE`. Every shipped migration file happens to end
in `PRAGMA foreign_keys = on;` (harmless for their original purpose as one-off scripts run through
a SQLite GUI), which is why this has to be reset explicitly on every connection rather than assumed
to default to off.

### Migrations (`Stats::migrate()`, `data/migrations/*.sql`)

Numbered SQL files (`1.sql`, `2.sql`, ...) are applied strictly in order, each wrapped in its own
transaction, tracked via a row inserted into the `migrations` table after each one succeeds. On
boot, `Stats` checks whether it needs to migrate at all, via any of three independent conditions:
the database file isn't writable yet (fresh install); a `data/migrations/MUST_MIGRATE` flag file
exists (a file shipped tracked in git, force-checking even against an existing database - reliable
whenever an update replaces the whole plugin directory, e.g. a GPM download or tarball extraction,
since the flag's tracked content reappears with everything else); or `hasPendingMigrations()` finds
a numbered `*.sql` file beyond what the `migrations` table has recorded as applied, independent of
the flag file. That third condition (added 2026-08-22) exists specifically because the flag alone
is not a reliable trigger under a `git pull`-based deployment - see "Notable past bugs" in
`ARCHITECTURE.md` for the real incident that prompted it. Whichever condition fires, `migrate()`
deletes the flag file at the end of a successful run if it happens to exist (it may not, when only
the third condition fired). There is currently no down-migration/rollback mechanism - migrations
are additive only (e.g. `referer` was added as a new column in migration 3 rather than replacing
`refer` from migration 1; see "Known schema quirks" below).

Before a migration file's SQL is executed, `Stats::skipExistingColumns()` strips any
`ALTER TABLE ... ADD COLUMN ...` statement whose column already exists on that table (checked via
`PRAGMA table_info`), making migrations 2/3/9 (the only files that currently `ADD COLUMN`)
idempotent - and any future `ADD COLUMN` migration too, since the check is generic rather than
tied to a specific column name. This matters because the `migrations` table's recorded version can
legitimately be behind the database's actual schema - most commonly a database
copied/migrated from an existing Page Stats installation, whose own schema may already contain
columns a later Page Insights migration also adds (see `docs/HISTORY.md`, bug #30). Without this,
re-running such an `ALTER TABLE ADD COLUMN` throws `duplicate column name` (SQLite has no
`ADD COLUMN IF NOT EXISTS`, unlike `CREATE TABLE`) - and since a migration file's statements all
run through one `PDO::exec()` call, that error would abort every statement after it in the same
file too, including its closing `COMMIT TRANSACTION;`. Every other migration statement
(`CREATE TABLE IF NOT EXISTS`, `DROP TABLE IF EXISTS` + `CREATE TABLE`, `CREATE INDEX`, `PRAGMA`)
is already idempotent by construction and needs no such handling.

### Table `data` (one row per page hit)

| Column | Type | Added in | Notes |
|---|---|---|---|
| `id` | `INTEGER PRIMARY KEY AUTOINCREMENT` | 1 | Also used as `events.session_id`'s (unenforced) reference target. |
| `ip` | `VARCHAR(255)` | 1 | Raw client IP; `(anonymous)` fallback when unavailable. Used to key anonymous visitors in User Detail. |
| `country` | `VARCHAR(65)` | 1 | ISO 3166-1 alpha-2 code from the geo country index (see below), or `unknown`. |
| `city` | `VARCHAR(128)` | 1 | Still written on every hit, always `'unknown'` since the 2026-08-15 IP2Location removal - the RIR-based replacement is country-only. Kept only so `collect()`'s column list didn't need a migration; see "Geolocation" in `ARCHITECTURE.md`. |
| `region` | `VARCHAR(128)` | 1 | Same as `city`: always `'unknown'`, kept for the same reason. |
| `route` | `VARCHAR(255)` | 1 | Page/request path. Indexed (see below). |
| `page_title` | `VARCHAR(255)` | 1 | Page title, or the raw URI on a 404 (`isNotFound` branch in `collect()`). |
| `user` | `VARCHAR(255)` | 1 | Logged-in username, empty for anonymous visitors. |
| `date` | `DATETIME` (stored as `TEXT`) | 1 | ISO-8601 via PHP's `DateTimeImmutable::format('c')`, **with whatever UTC offset was locally in effect at write time** - not normalized to a fixed offset. See "Date storage" below. Indexed (see below). |
| `http_code` | `INTEGER(3)` | 1 | Unused (always `NULL`) from migration 1 until 2026-08-19. Now populated on every write: `200` or `404` only (`collect()` can't reliably determine anything else at that hook), `NULL` for rows written before this was wired up. `Stats::statusCodeSummary()` bins anything that isn't exactly `200`/`404` - including `NULL` - into a forward-compatible "other" bucket. |
| `user_agent` | `STRING(255)` | 1 | Raw `User-Agent` header. Read back and displayed in the User Detail page's page-history table. |
| `refer` | `STRING(255)` | 1 | **Dead column.** Not part of `collect()`'s `INSERT` column list at all (that binds `referer`, added in migration 3 - see next row) - a leftover from the original Page Stats schema, never removed since migrations here are additive-only. Do not use; if referrer tracking is ever built out, it belongs on `referer`, not this column. |
| `is_bot` | `BOOLEAN` | 1 | Written from `Stats::isBot()` on every hit. Read back since 2026-08 by the Admin2 dashboard's "Hide bots" filter, which turns `?hide_bots=1` into `Stats::query()`'s generic equality filter `['is_bot' => 0]` (see "Hide bots filter" in `ARCHITECTURE.md`) - unrelated to the `log_bot` config option, which decides at write time whether a recognized bot hit gets inserted at all in the first place; `is_bot` only classifies hits that *were* inserted. See "Bot detection reliability" below before treating this column as authoritative. |
| `browser` | `STRING(100)` | 2 | From `Grav\Common\Browser`. Feeds `topBrowsers()`. |
| `browser_version` | `STRING(20)` | 2 | Ditto. |
| `platform` | `STRING(255)` | 2 | Feeds `topPlatforms()`. |
| `referer` | `STRING(500)` | 3 | `$_SERVER['HTTP_REFERER']` or empty string. Written on every hit but currently never read back anywhere (no referrer-analysis feature exists yet - see the project's ToDo history). |
| `environment` | `VARCHAR(255)` | 9 | Grav's `config('environment')` value at collection time - which site a hit belongs to, in a Grav multisite install sharing this plugin installation across several sites. `NULL` for every row written before migration 9. See "Multisite (environment) scoping" below. |

### Bot detection reliability (`is_bot`, `bot_regexp`)

`Stats::isBot()` classifies a hit by a single case-insensitive `preg_match()` of the request's
`User-Agent` header against the `bot_regexp` config array (a flat OR of substrings/patterns,
`page-insights.yaml`'s shipped default has ~270 entries plus a small dated block of common AI/
training crawlers added 2026-08-21 - see that file's own comments). Two things worth knowing
before relying on `is_bot` for anything beyond "a reasonable best guess":

- **False negatives are structural, not a bug.** Any traffic that doesn't self-identify via its
  `User-Agent` - a scraper spoofing a real browser's UA being the most common case in practice -
  is invisible to this method by construction; no UA-substring list can catch it. Upstream Page
  Stats issue reports back this up directly: one reporter had `log_bot` *disabled* and still saw
  large volumes of unrecognized automated-looking traffic, meaning it was never classified as a
  bot in the first place, let alone excluded.
- **No retroactive re-classification.** `is_bot` is computed once, at `INSERT` time, from whatever
  `bot_regexp` happened to be configured that moment. Editing `bot_regexp` later (an explicitly
  supported, admin-editable config field) only changes classification for *future* hits - existing
  rows keep whatever value they got when they were written. There is no migration or CLI command
  that re-evaluates historical rows against a changed list.

None of this makes the column unreliable for its actual purpose (a coarse, best-effort filter) -
it just means "hidden by the bots filter" should be read as "recognized as a bot by the UA list in
effect when the hit was collected", not "confirmed non-human".

### Table `events` (added in migration 4)

Free-form, low-volume side events tied to a page hit (e.g. time-on-page pings), written via
`Stats::collectEvent()`.

| Column | Type | Notes |
|---|---|---|
| `id` | `INTEGER PRIMARY KEY AUTOINCREMENT` | |
| `date` | `DATETIME DEFAULT (CURRENT_TIMESTAMP)` | |
| `session_id` | `INTEGER REFERENCES data (id)` | **Not enforced** - see "Connection setup" above. `collectEvent()` still validates the referenced `data` row exists with an explicit `SELECT` before inserting, since the schema-level reference can't be relied on. |
| `event` | `VARCHAR(255)` | Truncated to 255 chars server-side (`MAX_EVENT_STRING_LENGTH`) regardless of column width, since this table is reachable through an unauthenticated endpoint - see below. |
| `value` | `VARCHAR(255)` | Same truncation. |

`collectEvent()` also caps events per session at 2000 (`MAX_EVENTS_PER_SESSION`). Both limits exist
because `/event-collection` has no auth, nonce, or rate limiter in front of it (a frontend route,
so the `api` plugin's own limiter never sees it) - without them, anyone could insert rows
indefinitely until disk fills up.

### Table `migrations`

| Column | Type | Notes |
|---|---|---|
| `id` | `INTEGER PRIMARY KEY AUTOINCREMENT` | |
| `version` | `INTEGER` | The numeric filename (`N.sql`) that was applied. |
| `date` | `DATE DEFAULT (CURRENT_TIMESTAMP)` | When it was applied. |

### Indexes (migrations 5 and 6)

```sql
-- migration 5
CREATE INDEX IF NOT EXISTS idx_data_route ON data (route);
CREATE INDEX IF NOT EXISTS idx_data_date ON data (date);

-- migration 6
CREATE INDEX IF NOT EXISTS idx_data_date_normalized ON data (datetime(date));
CREATE INDEX IF NOT EXISTS idx_events_session_id ON events (session_id);
```

Two independent single-column indexes (`route`, `date`) rather than one composite `(route, date)`
index. A composite index only helps a query that also filters on its leading column - but
`Stats::query()`'s generic `$params` filter mechanism (see "Backend: generic query filter" in
`ARCHITECTURE.md`) means most call sites filter on `route` *or* the date range, not reliably both
together in a way that would line up with a fixed composite column order. Two single-column indexes
let SQLite's query planner use whichever one (or both, via a bitmap intersection) actually matches a
given call's filters, without betting on one particular combination.

**`idx_data_date_normalized` (migration 6) - an expression index, not on `date` itself.** Every
date-range filter compares `datetime(date) BETWEEN datetime(:from) AND datetime(:to)` (see "Date
storage and comparison" below) - `idx_data_date` is built on the raw `date` column, and SQLite's
query planner will not match a plain-column index against a query that wraps the column in a
function call. Confirmed via `EXPLAIN QUERY PLAN` against a realistically sized test database: every
date-range-filtered query - which by 2026-08 is nearly every dashboard widget, including the ones
the "Hide bots" filter now touches dashboard-wide - was doing a full table `SCAN` of `data` despite
`idx_data_date` existing, on every single request, getting slower as the table grows. This is what
the previous session's dashboard-slowdown reports traced back to. Adding an index on the *exact same
expression* the WHERE clause already uses (`datetime(date)`, not `date`) lets SQLite match it and
turns that `SCAN` into a `SEARCH`. Both indexes are kept - `idx_data_date_normalized` for filtered
queries, `idx_data_date` for `recentPages()`'s unfiltered `ORDER BY date DESC LIMIT n`, still a
different query shape an expression index doesn't help with.

**`idx_events_session_id` (migration 6).** `events` had no index at all before this. Classic Admin's
"Recently viewed pages" widget calls `Stats::timeOnPage()` once per displayed row (up to 1000 times
on the dedicated "view last 1000 pages" page) to look up that row's session by `session_id` - each
call was its own full table `SCAN` of `events`. Also benefits `collectEvent()`'s own per-hit session
lookup on the unauthenticated `/event-collection` endpoint.

### Date storage and comparison

`date` is stored as ISO-8601 text carrying whatever UTC offset was in effect on the web server at
write time (e.g. `+02:00` in summer) - rows are **not** normalized to a single fixed offset before
being written. A plain `date BETWEEN :from AND :to` (or `date < :cutoff`) is a pure lexicographic
string comparison in SQLite, which silently gives wrong results across rows stored under different
offsets: a `+02:00` row for "just now" sorts *after* a UTC `now` bound, because its hour digits are
numerically higher for the same real instant, which excludes exactly the freshest hits from a
"recent" range. Every comparison against `date` in this codebase (`Stats::query()`'s date-range
filter, `pruneData()`'s cutoff) wraps both sides in SQLite's `datetime()` function, which parses and
re-normalizes any valid offset before comparing - a plain text comparison here was a real,
previously-shipped bug (see "Notable past bugs" in `ARCHITECTURE.md`). Any new code that filters or
sorts by `date` needs the same `datetime(...)` wrapping.

### `VACUUM` and WAL

`Stats::vacuum()` runs `PRAGMA wal_checkpoint(TRUNCATE)` both immediately before measuring the
"before" file size and immediately after `VACUUM`, before measuring "after". Under `journal_mode =
WAL` (always on for this connection - see above), `VACUUM`'s rewritten pages land in the WAL file
first like any other write; without an explicit checkpoint, `filesize()` on the main database file
reports the same size before and after even though `VACUUM` worked correctly - verified against a
scratch database. Skipping this checkpoint step doesn't lose data, just makes a successful `VACUUM`
*look* like a no-op in any before/after size report.

### Growth and maintenance

Pruning (`prune`/`events:prune-orphans` CLI commands, the Admin2 database maintenance dialog, and
optional automatic scheduling) and `vacuum` operate on this schema but are workflow/UI concerns
documented in `ARCHITECTURE.md` ("CLI commands", "Admin2 database maintenance dialog", "Automatic
scheduling") rather than schema design - not duplicated here.

### Rollups (`rollup_daily`, `rollup_route`, `rollup_country`, `rollup_browser`, `rollup_platform`,
`rollup_state` - migrations 7 and 8)

`idx_data_date_normalized` (above) fixes SQLite matching the wrong index for a date-range filter,
but doesn't change what happens *after* the index seek: `EXPLAIN QUERY PLAN` against a synthetic,
realistically sized (3M-row) `data` table showed `SEARCH ... USING INDEX idx_data_date_normalized`
immediately followed by `USE TEMP B-TREE FOR GROUP BY` / `... FOR count(DISTINCT)` (twice) / `... FOR
ORDER BY` - all four scale with the number of *matched rows*, not the index. Measured on that same
database (~1M hits/month accumulated over 90 days): a single `pagesSummary()` call over the full
range took ~12.9s, `totalUniqueVisitors()` (no `GROUP BY` at all, just one `COUNT(DISTINCT ip)`) took
~17.4s, and a full ~10-widget dashboard load reached ~115s. The index was necessary but not
sufficient once a site's accumulated traffic reaches roughly this range.

`rollup_daily`/`rollup_route`/`rollup_country`/`rollup_browser`/`rollup_platform` precompute one row
per `(day, is_bot[, dimension])` via `Stats::rollupDay()`, so a query against them scales with the
number of *days* in the requested range instead of the number of matched hits - turning a GROUP BY
over millions of rows into one over at most a few hundred. Five narrow tables, not one wide one -
same reasoning at each step: a single cross-product table would multiply row counts by every
dimension combination, most of which no query here ever asks for together (see the comment on
`rollup_country` in `data/migrations/8.sql` for the fuller version of this argument). Dimensions:

- `rollup_daily`: one row per `(day, is_bot)` - `hits`, `visitors`/`users` (see below), and the three
  `statusCodeSummary()` buckets (`http_200`/`http_404`/`http_other`).
- `rollup_route`: one row per `(day, is_bot, page_title)` - same `hits`/`visitors`/`users`, plus
  `route` (`MIN(route)` per group - see the comment on the table in `data/migrations/7.sql` for why
  it's keyed by `page_title`, matching `pagesSummary()`'s pre-existing `GROUP BY page_title` exactly,
  not by `route`).
- `rollup_country`/`rollup_browser`/`rollup_platform` (migration 8): one row per
  `(day, is_bot, country|browser|platform)` - `hits` only, no `visitors`/`users` (neither
  `topCountries()`/`topBrowsers()`/`topPlatforms()` has ever returned those, only `hits` and a
  computed `share`). Deliberately no `route` column, unlike `rollup_route` - `topCountries()` etc.
  also serve a per-route breakdown (`page-details.html.twig`'s "top countries/browsers/platforms for
  this one page") and a per-visitor one (`user-details.html.twig`), but both are single-entity
  lookups, not the full-dashboard aggregate scan this rollup work targets, so a `route`/`user`/`ip`
  -filtered call keeps using each method's original live query - see "Read path" below.

**`visitors`/`users` are a deliberate, documented approximation for any range spanning more than one
day.** Each is an exact `COUNT(DISTINCT ip)`/`COUNT(DISTINCT user)` *for that single day* - correct
in isolation, but summing several days' worth (the only cheap thing a rollup can do without a
mergeable sketch structure like HyperLogLog, judged too much complexity/dependency weight for this
plugin) overcounts a visitor who came back on more than one of the summed days. `hits` has no such
issue - a count is exact regardless of how many days it's summed over. User-confirmed trade-off
(2026-08-22): approximate-and-labelled beats exact-but-slow, given the benchmark above showed
`COUNT(DISTINCT ...)` itself, not just combined with a `GROUP BY`, is one of the most expensive query
shapes at this scale - there's no live-query rewrite that avoids that cost.

**Read path.** Eight `Stats` methods now have a rollup fast path, all following the same shape:
`pagesSummary()` (migration 7); `topCountries()`/`topBrowsers()`/`topPlatforms()`/
`statusCodeSummary()`/`totalUniqueVisitors()`/`totalUniqueUsers()`/`siteSummary()` (migration 8).
Each checks, at the top of the method, whether `$dateFrom`/`$dateTo` are both set *and* `$params`'
keys are a subset of what that method's rollup table(s) can answer (`pagesSummary()` allows
`is_bot`/`route`; the other seven allow `is_bot` only - see each table's own entry above for why) -
if not, the method falls straight through to its original, byte-identical live query, exactly as
before any rollup existed. `topUsers()`/`recentPages()` have no rollup fast path (not part of this
work) and always run live.

Every rollup-backed method shares `Stats::rollupInteriorCoverage($dateFrom, $dateTo)` for the same
boundary-safety rule: the *first and last* calendar day touched by `[$dateFrom, $dateTo]` always go
through a live query against `data`, never the rollup, even when both are already covered by
`rollupStatus()`. Only `$dateFrom`/`$dateTo` being exact day boundaries would make summing the
*whole* first/last rollup day safe; a caller passing e.g. "now minus 30 days" (an arbitrary instant,
not necessarily midnight) does not guarantee that, and assuming it does was a real,
caught-by-benchmark-comparison bug during `pagesSummary()`'s original development (see "Notable past
bugs" #19 in `ARCHITECTURE.md`) - `rollupInteriorCoverage()` exists specifically so that fix only
had to be written, and gotten right, once. Only the days strictly *between* the two boundary days
are ever served from the rollup. For a multi-week/-month range this costs two live-queried days out
of many - negligible next to the win from the rest; for a range spanning fewer than three calendar
days (no interior day at all) or one `rollupStatus()` hasn't reached yet, the whole range falls back
to live, transparently.

Re-verified with the same methodology as `pagesSummary()`'s original rollout - not just
`EXPLAIN QUERY PLAN`/timing, but a second script comparing every rollup-backed method's output
against a direct live-query reference on the same synthetic (3M-row, ~90-day) database, across
multiple ranges and `hide_bots` states, plus the route/user-filter-bypasses-the-rollup and
no-interior-day edge cases. All matched exactly for `hits`-based figures (`topCountries()` etc.,
`statusCodeSummary()`, `siteSummary()`'s `hits` series); `visitors`/`users`-based figures
(`totalUniqueVisitors()`/`totalUniqueUsers()`, `siteSummary()`'s `visitors`/`users` series)
consistently matched or *exceeded* the exact reference, never fell short - the expected direction for
the same documented summed-per-day approximation `pagesSummary()` already uses (see below). Measured
on the same 89-day/3M-row range used for the original `pagesSummary()` benchmark, `hide_bots` on:
`topCountries()`/`topBrowsers()`/`topPlatforms()`/`statusCodeSummary()` each dropped from roughly
9.1-9.4s to ~90-105ms (~90-104x); `totalUniqueVisitors()` from ~21.7s to ~110ms (~196x);
`totalUniqueUsers()` from ~8.1s to ~87ms (~93x); `siteSummary()`'s three queries combined from ~29.1s
to ~311ms (~94x) - all seven together, ~96.2s down to ~0.88s (~109x).

**Write path (`Stats::rollupDay()`):** deletes then re-inserts that single day's rows in all five
rollup tables (idempotent - safe to rebuild any day, e.g. after a bug fix, without double-counting),
then advances `rollup_state.last_rolled_up_day` - but only forward, via `ON CONFLICT ... DO UPDATE
... WHERE excluded.last_rolled_up_day > rollup_state.last_rolled_up_day`, so rebuilding an old day
out of order (e.g. `rollup:build --date=...` for one historical day) never regresses how far the read
path thinks the rollup reaches. The day boundary itself uses the same `date(datetime(date), :offset)`
calendar-day bucketing already used by `recentPages()`/`siteSummary()` - not a plain UTC day - so a
rolled-up day groups exactly the rows a live query would group into it; the `WHERE` clause narrows
via `idx_data_date_normalized` with a generous ±1 day pad first (covers any real-world UTC offset)
before applying the exact per-offset equality, so building a rollup day is itself an index `SEARCH`,
not a scan, despite running once per day over the whole table's history. Backfilling all five tables
across a full 90-day history over the same 3M-row database took ~83s - a one-time cost per
`rollup:build --from=...` run, not per request.

`rollup_state` exists as its own tiny table rather than deriving "how far is the rollup built"
from `MAX(day)` in `rollup_daily`/`rollup_route` - a real calendar day with literally zero traffic
(bots or humans) would write no rows to either table, which `MAX(day)` can't distinguish from "not
rolled up yet".

Building/refreshing is always either the `rollup:build` CLI command or the optional
`rollup_auto_build: daily` scheduled job (`PageInsightsPlugin::registerRollupBuildJob()`) - never
triggered from `collect()` itself. An SQLite trigger that updated the rollup on every insert was
considered and rejected: it would add write-path cost/risk to every single page hit, in the same
request path this plugin has previously had to specifically harden against lock contention
(`busy_timeout`/WAL, see "Connection setup" above) - a daily batch job keeps that hot path
untouched. A first-time backfill of existing history is never automatic (see `rollup:build`'s own
docblock) - a potentially long-running, resource-intensive operation over months of history
shouldn't run unexpectedly on a bare command invocation or a newly-enabled scheduled job.

### Multisite (environment) scoping (`environment` column - migration 9)

Codeberg Issue #3: a Grav installation serving several sites from one shared installation (Grav's
own multi-site mechanism - see [learn.getgrav.org/17/advanced/multisite-setup](https://learn.getgrav.org/17/advanced/multisite-setup))
previously had every site's hits/aggregates mixed together, since neither `data` nor any rollup
table had a column identifying which site a row belonged to - `db`'s path is a plain path relative
to the (shared) Grav installation root, identical for every site.

**Reuses Grav's own `environment` concept rather than inventing a new one.** `config('environment')`
(`Grav\Common\Config\Setup`) defaults to the current request's hostname, with an admin-configurable
alias mechanism already built into Grav core for merging e.g. `www.` and the bare domain into one
environment - this plugin doesn't need its own site-identification logic or config option at all.
`PageInsightsPlugin::currentEnvironment()` reads it for the request-scoped `Stats` instances
(page hit collection, both admin UIs' dashboard reads); every CLI command and scheduled job
(`prune`, `vacuum`, `events:prune-orphans`, `prune:bots`, `prune:notfound`, `rollup:build`) passes
none at all (`Stats`'s third constructor argument defaults to `null`) - those operate across every
site's data at once by design, never "the current site". A single-site install has exactly one
`environment` value across every row it ever writes, so every query change below is a provable
no-op for the overwhelmingly common case: an added `WHERE`/`GROUP BY` column that never actually
narrows or splits anything when there's only ever one distinct value to compare against.

**Legacy rows (`environment IS NULL`) stay visible to every site, not hidden from all of them.**
Migration 9 adds the column nullable, with no default and no backfill - there is no reliable way to
attribute already-collected, already-mixed historical hits to one particular site after the fact.
Every read path (`Stats::query()`'s generic mechanism, and each `*ViaRollup()`/`*RollupPart()`
method's own hand-built `$where`, via the shared `appendEnvironmentFilter()` helper) treats this as
`(environment = :environment OR environment IS NULL)`, never a plain equality - an upgraded
install keeps its pre-upgrade history visible on every site's dashboard exactly as before, while
every *new* hit from this point on is correctly split by site. (User-confirmed trade-off,
2026-08-2x - the alternative, hiding all pre-upgrade history from every site, was rejected as a
worse first-upgrade experience for no accuracy gain: that history is already mixed either way.)

`Stats::query()`'s environment condition is skipped entirely for one call site - `timeOnPage()`'s
query against `events` (`$scopeByEnvironment = false`) - since that table has no `environment`
column at all (see below).

**`events` needs no `environment` column.** Every row is tied to a `data` row via `session_id`,
which was already correctly scoped by `environment` when it was written - there is nothing to
duplicate or additionally filter by.

**Every one of the five rollup tables (migrations 7/8) also needed `environment` added to its
`PRIMARY KEY`** (`rollupDay()`'s `INSERT ... SELECT ... GROUP BY` extended to include it, one more
`GROUP BY` column each) - without it, the rollup fast path would keep merging every site's hits
into one row per `(day, is_bot[, dimension])`, correctly scoped live-query fallback or not: two
sites both getting occasional traffic on a page titled "Home" would merge into one `rollup_route`
row regardless of which site a dashboard request asked for. SQLite has no `ALTER TABLE` support for
changing a primary key in place, so migration 9 recreates all five tables empty rather than altering
them - existing rollup rows aren't preserved (same reasoning as the "data" rows above: they already
aggregate every site's hits together, with nothing to split them back apart by). This is safe under
the same idempotent-rebuild guarantee `rollupDay()` already relies on elsewhere: migration 9 also
clears `rollup_state`, so `rollupStatus()` returns `null` immediately after upgrading and every
rollup-backed method transparently falls back to its live query - exactly like a fresh install that
has never run `rollup:build` yet - until the next `rollup:build` run (manual, or the scheduled
`rollup_auto_build` job) repopulates all five tables from `data`, correctly split by environment
this time. **Admins should (re-)run `rollup:build` after upgrading to this version** - see
`CHANGELOG.md`.

**Deliberately no index on `environment`.** A first attempt added `idx_data_environment` alongside
the column, on the reasonable-looking assumption that any new filter column deserves an index (the
same reasoning behind `idx_data_route`/`idx_data_date` in migration 5). Measured against the same
synthetic 3M-row/90-day database as the rollup benchmark above, this made things dramatically
*worse*, not better: `EXPLAIN QUERY PLAN` showed SQLite choosing a `MULTI-INDEX OR` plan driven by
`idx_data_environment` (one index seek per side of the `OR`) *instead of* `idx_data_date_normalized`
- including for queries that also carry a narrow date-range condition, such as the single-boundary-
day live queries every rollup-backed method falls back to (see "Read path" above). Since
`environment` has low cardinality (a handful of distinct values even on a large multisite install),
each `OR` branch's index seek matches a large fraction of the whole table, and the date range then
has to be re-checked row-by-row across all of them - the exact opposite of what
`idx_data_date_normalized` was added for in migration 6. A single boundary-day query went from
~34ms (`idx_data_date_normalized`, no environment index) to ~830ms (`MULTI-INDEX OR` via
`idx_data_environment`) - roughly 24x worse, multiplied by however many such boundary queries one
dashboard load makes (two per rollup-backed widget, more for `siteSummary()`'s three-query shape) -
an ~18s full dashboard load in the benchmark, against ~115s before rollups existed at all and
well under a second after this migration's actual (index-free) version. Without any index on
`environment`, there is nothing to lure the planner away from `idx_data_date_normalized` - confirmed
via `EXPLAIN QUERY PLAN` to fall back to exactly the same plan as before this column existed, with
`environment` applied as a plain filter over the already date-narrowed rows. Re-verified end to end
against the same 3M-row/90-day database, now split across four `environment` values plus a legacy
(no-environment) chunk: full 8-widget single-site dashboard load ~416ms (same order of magnitude as
the original rollup benchmark, not a regression), every `hits`-based figure exactly matched a
direct live-query reference scoped the same way (including legacy rows correctly folded in), the
`visitors`/`users` approximation stayed in the same documented direction (≥ the exact reference,
never under), and two different sites' figures were confirmed to differ from each other and each
match their own reference - the isolation this whole migration exists for.

## Geo country index (`geo_db_index_path` config key, default `user/data/page-insights/geo-country-index.bin`)

Not a SQL database. A single self-built, self-contained binary file, read via direct `fseek`/`fread`
binary search (`classes/Geolocation/CountryLookup.php`) - deliberately not SQLite or any other
embedded-database engine, since the access pattern is a single point lookup per page hit with no
need for joins, transactions, or concurrent writers, and the file needs to stay small enough (a
few MB) to fetch as a rolling release asset. See "Geolocation" in `ARCHITECTURE.md` for the full
history of *why* this replaced the earlier IP2Location BIN dependency; this section covers the
current format and the design decisions specific to it.

### On-disk format ("PIGC1", Page Insights Geo Country v1)

Authoritative byte-for-byte definition lives in `CountryIndexBuilder`'s class doc comment
(`classes/Geolocation/CountryIndexBuilder.php`) and is mirrored, independently, in
`CountryLookup::open()`'s parsing (`HEADER_SIZE`/entry-size constants in both classes - kept in
sync deliberately rather than sharing one constant, see the doc comment on `CountryIndexBuilder::
HEADER_SIZE`). This table is a human-readable copy of the same layout - if you change the format,
update all three places.

| Offset | Size | Field |
|---|---|---|
| 0 | 5 | Magic `"PIGC1"` |
| 5 | 4 | `builtAt` - Unix timestamp, `uint32` big-endian |
| 9 | 8 | `sourceDate` - 8 ASCII digits, `YYYYMMDD`, or `"00000000"` if unknown |
| 17 | 4 | `ipv4EntryCount` - `uint32` big-endian |
| 21 | 4 | `ipv6EntryCount` - `uint32` big-endian |
| 25 | `ipv4EntryCount * 6` | IPv4 entries, sorted ascending by start address |
| ... | `ipv6EntryCount * 18` | IPv6 entries, sorted ascending by start address |

Each IPv4 entry is 6 bytes: 4-byte start address (`uint32` big-endian) + 2-byte ISO 3166-1
alpha-2 country code. Each IPv6 entry is 18 bytes: 16-byte start address (network byte order) + the
same 2-byte country code.

### Design decisions

- **Gap-filled, not sparse.** Every possible address falls into exactly one entry - unallocated,
  reserved, or otherwise out-of-scope ranges get an explicit `UNKNOWN_CC` (`"ZZ"`, ISO 3166-1's
  reserved "unknown country" code) entry rather than being omitted. This is what lets a lookup
  always be a plain "entry with the greatest start ≤ address" binary search, with no separate
  end-of-range check per entry - simpler and faster than a sparse range table would allow.
- **Sorted, fixed-width entries.** Both entry lists are sorted ascending and fixed-width per IP
  version, which is what makes direct binary search over the file possible in the first place
  (`CountryLookup::lookupIpv4()`/`lookupIpv6()` compute a byte offset from an index, `fseek` there,
  read exactly one entry). Adjacent same-country entries are merged at build time to keep the file
  small.
- **Streamed, not loaded into memory.** `CountryLookup` is instantiated fresh on every single page
  hit and never reads the whole file - only the fixed-size header, then one `fseek`/`fread` pair
  per binary-search probe. This has to stay cheap since it runs on the page-request hot path,
  unlike `CountryIndexBuilder::build()`/`fetchPrebuilt()`, which only ever run from an explicit
  admin action or a scheduled/CLI job.
- **Fails to `null`, never throws.** A missing file, a corrupt header, or an IP that resolves to
  `UNKNOWN_CC` all resolve to `isAvailable() === false` / `lookup() === null`, which `Geolocation.php`
  turns into the existing `'unknown'` fallback. A missing or stale geo database must never break
  page collection itself.
- **Atomic replace.** Both build paths (`build()` writing incrementally, `fetchPrebuilt()` writing
  the whole downloaded byte string at once) write to a temp file and `rename()` it into place, so a
  concurrent lookup never observes a half-written file, and a failed build/download never leaves a
  partially-overwritten index behind.
- **Validated before install.** `fetchPrebuilt()` parses and sanity-checks the downloaded bytes
  (magic bytes present, declared entry counts actually match the byte count) *before* touching the
  real `$outputPath` - a truncated or corrupted download is rejected without ever touching the
  previously-working index. An "Update now" click that fails leaves the site exactly as good as
  before the click, never worse.
- **No migration path (yet).** Unlike the stats database, there is no versioned upgrade mechanism
  for this format - a future incompatible change would need a new magic (e.g. `"PIGC2"`) and
  `CountryLookup`/`CountryIndexBuilder` would need to either support both or force a rebuild. Not a
  problem so far since the file is fully disposable and rebuilt on demand (there is no user data in
  it to migrate), but worth knowing before changing the byte layout of an existing field rather
  than only ever appending.

Which of the two build modes (`prebuilt` companion-repo download vs. local `raw` RIR build) is used,
and how that's wired into the CLI/scheduler/admin UI, is a workflow decision, not a format one - see
"Two update modes" in `ARCHITECTURE.md`.

---

## Auf Deutsch (Kurzfassung)

Diese Datei dokumentiert die beiden Datenspeicher des Plugins - Schema und die dahinterstehenden
Design-Entscheidungen (Indizes, Pragmas, Binärformat), soweit sie für Korrektheit oder Performance
relevant sind. Programmkonventionen und UI-/Workflow-Architektur bleiben in `ARCHITECTURE.md`;
diese Datei wird von dort aus verlinkt statt Inhalte zu duplizieren.

**Statistik-Datenbank** (`data.sqlite`, SQLite via PDO in `classes/Stats.php`): Pragmas
`busy_timeout = 5000`, `journal_mode = WAL`, `synchronous = NORMAL` sowie explizit `foreign_keys =
OFF` (die `events.session_id REFERENCES data(id)`-Referenz ist bewusst nur Dokumentation, nie
erzwungen - `pruneData()`/`pruneOrphanedEvents()` verlassen sich darauf). Migrationen
(`data/migrations/*.sql`) sind rein additiv, nummeriert, in der `migrations`-Tabelle protokolliert.
Tabellen `data` (ein Datensatz pro Seitenaufruf, u. a. mit einer toten/einer kaum genutzten Spalte:
`refer` komplett unbenutzt seit jeher, `referer` geschrieben aber bislang nirgends gelesen; `is_bot`
wird seit 2026-08 vom „Bots ausblenden"-Filter im Admin2-Dashboard gelesen - als reine
User-Agent-Klassifikation ohne rückwirkende Neubewertung bei geänderter `bot_regexp`-Liste ein
Best-Effort-Filter, keine Garantie), `events`
(Session-gebundene Zusatzereignisse, mit serverseitigen Limits gegen den unauthentifizierten
Collector-Endpunkt) und `migrations`. Zwei einzelne Indizes (`route`, `date`) statt eines
zusammengesetzten, weil der generische Filter-Mechanismus (`Stats::query()`) meist nur eine der
beiden Spalten pro Abfrage filtert. `date` wird mit dem jeweils lokal gültigen UTC-Offset
gespeichert, nie normalisiert - Vergleiche brauchen deshalb durchgängig `datetime()`-Wrapping.
`VACUUM` unter WAL braucht ein explizites `PRAGMA wal_checkpoint(TRUNCATE)`, sonst zeigt eine
Dateigrößenmessung davor/danach fälschlich keine Änderung.

**Geo-Country-Index** (`geo-country-index.bin`, Format „PIGC1"): keine SQL-Datenbank, sondern eine
selbstgebaute, kompakte Binärdatei, die per direktem `fseek`/`fread` binär durchsucht wird (nichts
wird vollständig in den Speicher geladen - läuft bei jedem einzelnen Seitenaufruf). Byte-Layout
(Magic, `builtAt`, `sourceDate`, Einträge je IP-Version) ist maßgeblich im Klassenkommentar von
`CountryIndexBuilder` dokumentiert und hier als lesbare Kopie gespiegelt - bei einer Formatänderung
müssen beide Stellen (plus `CountryLookup`) synchron gehalten werden. Zentrale Designentscheidungen:
lückenlose Abdeckung (unbelegte Bereiche bekommen einen expliziten „unbekannt"-Eintrag statt
ausgelassen zu werden), sortierte, fest breite Einträge als Voraussetzung für die Binärsuche,
atomares Schreiben (Temp-Datei + `rename()`), Validierung heruntergeladener Daten vor dem
Überschreiben des bestehenden Index, und Fail-Safe-Verhalten (fehlender/defekter Index bricht die
Seitenauslieferung nie). Noch kein Migrationspfad für Formatänderungen vorhanden - bislang unnötig,
da die Datei jederzeit verlustfrei neu gebaut werden kann.
