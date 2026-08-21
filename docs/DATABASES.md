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
boot, `Stats` checks whether it needs to migrate at all: either the database file isn't writable
yet (fresh install) or a `data/migrations/MUST_MIGRATE` flag file exists (forces a re-check even
against an existing database); that flag file is deleted at the end of a successful `migrate()`
run. There is currently no down-migration/rollback mechanism - migrations are additive only (e.g.
`referer` was added as a new column in migration 3 rather than replacing `refer` from migration 1;
see "Known schema quirks" below).

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
| `is_bot` | `BOOLEAN` | 1 | Written from `Stats::isBot()` on every hit, but never read back anywhere (no query filters or displays it) - bot *exclusion* is decided separately at write time via the `log_bot` config option, before the row is even inserted. |
| `browser` | `STRING(100)` | 2 | From `Grav\Common\Browser`. Feeds `topBrowsers()`. |
| `browser_version` | `STRING(20)` | 2 | Ditto. |
| `platform` | `STRING(255)` | 2 | Feeds `topPlatforms()`. |
| `referer` | `STRING(500)` | 3 | `$_SERVER['HTTP_REFERER']` or empty string. Written on every hit but currently never read back anywhere (no referrer-analysis feature exists yet - see the project's ToDo history). |

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

### Indexes (migration 5)

```sql
CREATE INDEX IF NOT EXISTS idx_data_route ON data (route);
CREATE INDEX IF NOT EXISTS idx_data_date ON data (date);
```

Two independent single-column indexes rather than one composite `(route, date)` index. A composite
index only helps a query that also filters on its leading column - but `Stats::query()`'s generic
`$params` filter mechanism (see "Backend: generic query filter" in `ARCHITECTURE.md`) means most
call sites filter on `route` *or* the date range, not reliably both together in a way that would
line up with a fixed composite column order. Two single-column indexes let SQLite's query planner
use whichever one (or both, via a bitmap intersection) actually matches a given call's filters,
without betting on one particular combination.

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
Tabellen `data` (ein Datensatz pro Seitenaufruf, u. a. mit zwei toten/kaum genutzten Spalten:
`refer` komplett unbenutzt seit jeher, `is_bot`/`referer` geschrieben aber nirgends gelesen), `events`
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
