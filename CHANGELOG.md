## unreleased

1. [](#improved)
    * improved: moved the Admin2 dashboard toolbar's status text (database size, next scheduled
      geo-DB update/auto-prune) out of the toolbar itself into its own status line above it - once
      the new "Scan detection" button (v3.4.0) pushed the row's total content close to the
      available width, `justify-content: space-between` without an explicit `gap` stopped
      guaranteeing any minimum spacing, letting the range buttons and the action buttons end up
      flush against each other with no gap at all (reported against the production instance,
      2026-08-24). The toolbar itself also gained an explicit `gap` and `flex-wrap` as a safety
      net for future buttons. The new status line scrolls as a seamless, endless marquee if its
      content doesn't fit (paused on hover/focus, skipped entirely under `prefers-reduced-motion:
      reduce` in favor of a plain ellipsis truncation) - see `docs/ADMIN-UI.md` "Dashboard toolbar
      status line" for the full design. Live-testing (2026-08-24) then surfaced a follow-up bug in
      that first version - the status text showed doubled and the marquee never triggered, even at
      very narrow window widths - caused by `.status-line` missing `min-width: 0` (a flex item
      otherwise defaults to `min-width: auto`, letting its `nowrap` content escape both the
      `overflow: hidden` clipping and the JS overflow measurement). Fixed together with switching
      the second, `aria-hidden` marquee copy from always-present to added dynamically only once
      overflow is actually confirmed. Also right-aligned the status line (`text-align: right`) so
      it sits flush above the "Maintain database" button and its neighbors, matching where the
      database-size text sat before it moved out of the toolbar.

# v3.4.0
# 08/24/2026 ([94e55a8](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/94e55a851e43af724afe1a089b14734d5e456a62))

1. [](#new)
    * feat: new opt-in "Scan detection" feature - periodically matches recently collected 404 hits
      against an admin-curated list of known vulnerability-scan paths (new `scan_patterns` table,
      started empty; populate via `bin/plugin page-insights scan-patterns:import`, seeded from a
      bundled Fail2Ban.WebExploits-derived snapshot, or the new Admin2 "Scan detection" view) and
      raises an alert (new `scan_alerts` table) once one IP racks up too many distinct matches
      (default: 5) within a short window (default: 10 minutes) - typically automated probing
      rather than a stray broken link. Runs entirely as a new opt-in Scheduler job every 5 minutes
      (`scan_detection` config, default off) - never a request hook, so this adds no per-request
      overhead regardless of pattern-list size. Alerts surface as a dismissible Admin2 dashboard
      banner (`onApiDashboardNotifications`) and, optionally, email (`scan_detection_alert_email`,
      via Grav-Core's own `Scheduler\Job::email()` - requires the separate, official `email`
      plugin). Admin2-only (new "Scan detection" sidebar view), like the existing database
      maintenance dialog - see `docs/ARCHITECTURE.md` ("Scan detection"), `docs/DATABASES.md`
      ("Tables `scan_patterns` / `scan_alerts`"), and `docs/MAINTENANCE.md` ("Scan detection") for
      the full design, schema, and operational details.

# v3.3.2
## 08/24/2026 ([9015be0](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/9015be04739b2f9ef625722608d7e77b2c941aaf))

1. [](#bugfix)
    * bugfix: the v3.3.1 fix for Codeberg issue #6 only made `ALTER TABLE ... ADD COLUMN`
      idempotent. Reopened on the same issue once `migrate()` on that same reporter's database got
      further and failed with `table events already exists` (migration 4) - the one `CREATE TABLE`
      in the whole migration sequence that had no `IF NOT EXISTS` (a full statement-by-statement
      audit of all nine migration files, prompted by the reporter's explicit ask to check
      everything rather than patch statement-by-statement, confirmed it was the *only* other one).
      Fixed migration 4.sql directly (`CREATE TABLE IF NOT EXISTS events`) and generalized
      `Stats::skipExistingColumns()` into `skipAlreadyAppliedSchema()`, adding
      `skipExistingTables()`/`skipExistingIndexes()` as a safety net for any future migration
      statement that ships without `IF NOT EXISTS` - both correctly no-ops against every
      currently-shipped migration file. See `docs/DATABASES.md` ("Migrations") and
      `docs/HISTORY.md` (bug #31).

# v3.3.1
## 08/23/2026 ([f4437ac](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/f4437acb80063d6f1629d02892064f7f1fdd9a1c))

1. [](#bugfix)
    * bugfix: `Stats::migrate()` failed with `duplicate column name: browser` on a database
      copied/migrated from an existing Page Stats installation whose own schema already had the
      `browser`/`browser_version`/`platform` columns migration 2 adds, but had never run this
      plugin's own migration bookkeeping before (Codeberg issue #6). SQLite has no
      `ADD COLUMN IF NOT EXISTS` (unlike `CREATE TABLE`), so re-running that `ALTER TABLE ADD
      COLUMN` threw and aborted the rest of that migration file too. `migrate()` now strips any
      `ADD COLUMN` statement whose column already exists before executing a migration file -
      generic across migrations 2/3/9, not special-cased to `browser` - see `docs/DATABASES.md`
      ("Migrations") and `docs/HISTORY.md` (bug #30).

# v3.3.0
## 08/22/2026 ([850590e](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/850590e00e48f2e7e5e2b0b1f2fe2ec129d59aa5))

1. [](#new)
    * feat: Page Insights now keeps statistics separate per site in a Grav multisite install (several sites sharing one Grav installation, including this plugin) - previously every site's hits and dashboard aggregates were silently mixed together, since neither `data` nor any rollup table had a column identifying which site a row belonged to, and the plugin's own SQLite file path is a single path shared by the whole installation regardless of which site served the request (Codeberg Issue #3). New `data.environment` column (migration 9), reusing Grav's own `config('environment')` concept (defaults to the request's hostname, with an admin-configurable alias mechanism already built into Grav core for merging e.g. `www.` and the bare domain into one environment) rather than adding a plugin-specific site identifier or config option - a single-site install has exactly one `environment` value across every row it ever writes, so this is a no-op for the overwhelmingly common case. Every dashboard read (both admin UIs, all report widgets, Page/User Detail) is now scoped to the current site automatically; every CLI command and scheduled maintenance job (`prune`, `vacuum`, `events:prune-orphans`, `prune:bots`, `prune:notfound`, `rollup:build`) intentionally stays unscoped, operating across every site's data at once as before. Rows collected before this upgrade (`environment IS NULL`) remain visible on every site's dashboard rather than being hidden from all of them or guessed at - there's no reliable way to attribute already-mixed historical hits to one particular site after the fact; only hits collected from this version onward are actually split. See `docs/DATABASES.md`, "Multisite (environment) scoping" for the full design (including a first attempt - an index on `environment` - that measurably made things worse, not better, and was reverted; see below).
1. [](#improved)
    * improved: none of this required inventing a new central scoping mechanism - `Stats::query()`'s existing generic `$params` filter (already used by every non-rollup dashboard query) and a small new `appendEnvironmentFilter()` helper (for the five rollup-table queries, which build their SQL by hand rather than through `query()`) turned out to be enough.
2. [](#bugfix)
    * bugfix (caught before release, not shipped): the first version of this migration also added an index on `data.environment`, on the same reasoning as the existing `route`/`date` indexes. Benchmarked against the same synthetic 3M-row/90-day database used for the v3.1.9/v3.2.0 rollup work before release, this made every rollup-backed dashboard widget dramatically *slower*, not faster - `EXPLAIN QUERY PLAN` showed SQLite choosing a `MULTI-INDEX OR` plan driven by that new index instead of the existing `idx_data_date_normalized`, even for queries that also carry a narrow date-range condition, since `environment`'s low cardinality means each side of the `OR` matches a large fraction of the whole table. A single boundary-day query went from ~34ms to ~830ms (~24x), and a full 8-widget dashboard load from well under a second to ~18s in that benchmark - reverted before this ever shipped; there is deliberately no index on `data.environment`. See `docs/DATABASES.md` for the full measurement.

# v3.2.0
## 08/22/2026 ([336c8c4](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/336c8c45608fb2b7f121b70af21d8e1096316030))

1. [](#new)
    * feat: added an optional daily rollup for the dashboard's aggregate queries (top pages, top countries/browsers/platforms, status codes, unique visitors/users, chart data) - `rollup_daily`/`rollup_route` (migration 7), built by a new `Stats::rollupDay()` and kept current by a new `bin/plugin page-insights rollup:build` CLI command or an optional daily scheduled job (`rollup_auto_build`, disabled by default). Motivated by a synthetic-benchmark investigation (~1M hits/month accumulated over 90 days, same methodology as the v3.1.9 index fix): the v3.1.9 index fix was necessary but not sufficient at that scale - `EXPLAIN QUERY PLAN` still showed `GROUP BY`/`ORDER BY`/two `count(DISTINCT ...)` operations after the index seek, scaling with the number of matched rows regardless of indexing; a full dashboard load reached ~115s. `Stats::pagesSummary()` is the first method rewired onto the rollup as a proof of concept (see `docs/DATABASES.md`, "Rollups") - a 90-day query dropped from ~12.9s to ~0.1s in that same benchmark, with results verified to match the original live query exactly. The other aggregate methods are expected to follow the same now-proven pattern in a later release. `visitors`/`users` figures are a deliberate, documented approximation for any range spanning more than one day (summed per-day exact counts, which can overcount a visitor active on more than one of the summed days) rather than requiring a sketch-based (HyperLogLog) structure - `hits`/counts have no such issue and are always exact.
    * feat: extended the rollup to the seven remaining dashboard aggregate methods - `topCountries()`/`topBrowsers()`/`topPlatforms()` (new `rollup_country`/`rollup_browser`/`rollup_platform` tables, migration 8), `statusCodeSummary()`/`totalUniqueVisitors()`/`totalUniqueUsers()`/`siteSummary()` (answered directly from the existing `rollup_daily` table, no new table needed). Same boundary-day-safe approach as `pagesSummary()`'s original rollout, now shared via a new `Stats::rollupInteriorCoverage()` rather than a fresh copy of that logic per method; same rollup-eligibility rule too (a route/user/ip-filtered call, e.g. a Page/User Detail view, keeps using the original live query - only date-range + optional "hide bots" calls, which is what the main dashboard load actually sends, use the rollup). Verified against the same synthetic 3M-row/89-day database as the original benchmark, both for correctness (a second script comparing every method's rollup-backed output against a live-query reference, across multiple ranges/`hide_bots` states, plus route/user-filter-bypass and no-interior-day edge cases - all exact for `hits`-based figures, all correctly at-or-above the exact reference for the documented `visitors`/`users` approximation) and performance: these seven methods combined dropped from ~96.2s to ~0.88s on that same range (~109x) - see `docs/DATABASES.md`, "Rollups" for the full per-method numbers. `rollup:build`'s output now also reports country/browser/platform row counts per day.
    * feat: added two more presets to the Admin2 "Maintain database" dialog and, unlike that dialog's existing three presets, matching CLI commands - `Stats::pruneBotTraffic()`/`bin/plugin page-insights prune:bots` deletes every row tagged `is_bot = 1` regardless of age, and `Stats::pruneNotFoundHits()`/`bin/plugin page-insights prune:notfound` deletes every row with `http_code = 404` - both followed, like the existing presets, by orphaned-event cleanup and a `VACUUM`. Requested as a quick way to shrink a database dominated by crawler noise or broken-link 404s without waiting for `data_auto_prune_older_than`/a manual `prune --older-than` to reach that data by age. See `docs/ARCHITECTURE.md`, "Admin2 database maintenance dialog" and "CLI commands".
1. [](#bugfix)
    * bugfix: `Stats::migrate()` on an already-existing database only ever triggered via a `data/migrations/MUST_MIGRATE` flag file, tracked in git and deleted once a migration run succeeds - reliable for a deployment that replaces the whole plugin directory (GPM download, tarball), but silently never re-triggers under a `git pull`-based deployment, since a `pull` has nothing to "restore" for a tracked file whose content never changes between releases. Two real `git pull`-updated installations were found stuck on an old schema for weeks this way, including missing the v3.1.9 index fix entirely (a missing index doesn't error, it just silently stays slow) - see `docs/ARCHITECTURE.md`, "Notable past bugs" #20. Fixed with a new `Stats::hasPendingMigrations()`, checked on every boot alongside the existing flag check, comparing the highest `data/migrations/N.sql` on disk against the last version recorded in the `migrations` table - self-healing regardless of deployment method.
    * bugfix: three Classic Admin status lines (`next_geo_db_update`/`next_auto_prune` in the dashboard header, `builtAt` in the geo-DB status widget) rendered a fixed, non-localized `Y-m-d H:i` timestamp regardless of the admin's configured language, unlike their already-correct Admin2 equivalents (browser-native `toLocaleString()`). Same underlying bug class as the v3.1.9 date-localization fixes, missed at the time since these three carry a time-of-day and the existing `LocalizedDate` methods were day-only. Fixed with a new `LocalizedDate::dateTime()` / `page_insights_localized_datetime` Twig filter - see `docs/ARCHITECTURE.md`, "Notable past bugs" #21.
    * bugfix: the five rollup-feature blueprint keys added earlier in this release (`AUTO_SCHEDULE_DAILY`, `SECTION_ROLLUP`, `SECTION_ROLLUP_LABEL`, `ROLLUP_AUTO_BUILD_LABEL`, `ROLLUP_AUTO_BUILD_HELP`) shipped English-only, leaving the config tab's new "Dashboard rollups" section untranslated for `de`/`fr` admins - unlike every other config field. Added the missing `languages/de.yaml`/`languages/fr.yaml` entries in the same release rather than as a follow-up.

# v3.1.9
## 08/21/2026 ([5a7074e](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/5a7074e7c0233610ad3d26805bbaa81f3aeb4afc))

1. [](#improved)
    * improved: fixed a dashboard-wide performance bottleneck traced to the date-range filter used by nearly every dashboard query - `datetime(date) BETWEEN datetime(:from) AND datetime(:to)`, needed for correctness across differing UTC offsets (see `docs/DATABASES.md`) - silently defeating the existing `idx_data_date` index, so every date-filtered query (by now nearly all of them, including everything the "Hide bots" filter touches) ran a full table scan of `data` regardless of table size, confirmed via `EXPLAIN QUERY PLAN` against a realistic row count. Fixed with a new expression index matching the actual comparison SQLite runs (`idx_data_date_normalized`, migration 6). Also removed four redundant, separate `totalPageViews()` queries (`topCountries()`/`topBrowsers()`/`topPlatforms()`/`statusCodeSummary()` each used to run a whole extra query purely to compute a percentage's denominator, now derived from their own already-fetched result) and added a missing index on `events.session_id` (migration 6) - Classic Admin's "Recently viewed pages" widget calls `Stats::timeOnPage()` once per displayed row (up to 1000 times on the dedicated "view last 1000 pages" page), each previously a full table scan of `events`.
    * improved: Admin2 dashboard chart x-axis date labels now render in the admin's configured language (via the browser's `Intl.DateTimeFormat`, e.g. `21.08.` for German, `8/21` for English) instead of a fixed `DD.MM.` format regardless of language - the one remaining item from the original locale-aware-dates To Do.

2. [](#bugfix)
    * bugfix: Classic Admin's "Recently viewed pages" widget rendered its day-group headers in English regardless of the configured admin language (`day|date('F jS')`, Twig's/PHP's built-in date formatting, is never locale-aware for named months - found while localizing the Admin2 chart labels above). Now uses a new `page_insights_localized_day` Twig filter (`classes/LocalizedDate.php`), backed by PHP's `IntlDateFormatter` where available, with a neutral `Y-m-d` fallback (not a new hard requirement - `ext-intl` added to `composer.json`'s `suggest`, not `require`, matching how Grav core itself treats that extension).
    * bugfix: found while live-testing the two fixes above against real Grav 1.7/2.0 test instances - two more date displays had no formatting at all, not just the wrong one: Classic Admin's three dashboard charts (page views/unique visitors/unique users) fed a raw `YYYY-MM-DD` string straight into Chart.js as an x-axis label, and every "recently viewed"-style table in Admin2 (dashboard, Page/User Detail, Page/User search) showed an unformatted `day`/`time` concatenation. Both now go through the same locale-aware formatting as the other two fixes (`page_insights_short_day` Twig filter / a new `_formatRecentDate()` helper) - the Classic Admin chart axes deliberately matching Admin2's chart-axis format byte-for-byte per locale, so both dashboards' charts look the same.

# v3.1.8
## 08/21/2026 ([5d14b91](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/5d14b919070a734761c2e3c441c2ea79764a7511))

1. [](#new)
    * feat: added a "Hide bots" filter to the Admin2 dashboard (toolbar toggle, applies dashboard-wide - every KPI, chart and "top" list, plus the Page/User Detail views, not just one card) - backed entirely by the existing `data.is_bot` column via `Stats::query()`'s generic filter mechanism (`is_bot => 0`), no schema change needed. New `default_hide_bots` config field (default off, so existing dashboards' numbers don't change on upgrade) lets the admin make it the default on load, same pattern as the existing `default_pages_scope` setting. New `?hide_bots=1` query parameter accepted by every read endpoint in `PageInsightsApiController`. Prompted by two upstream Page Stats issues asking for bot/crawler filtering.
    * feat: both admin UIs now show, next to the database size, when the two optional automatic maintenance jobs (geo-db update, data prune) will next run - reusing `AutoSchedule::nextRun()` (already computing the same schedule for the actual cron registration) via a small addition to `Stats::dbStats()`, the one method already backing both UIs' database-size display. Omitted entirely for a job currently set to "disabled".
    * feat: the two manually triggered maintenance actions (geo-db rebuild, database maintenance) now write an info-level `grav.log` entry on success (who triggered it, from which admin UI, with what result) - visible via Tools -> Logs ("Grav System Log") in Admin2, so an admin can point a bug reporter at the log instead of needing DB access. The existing failure-case log calls for both actions now include the triggering username too.

2. [](#improved)
    * improved: modernized the default `bot_regexp` list, unchanged since the original Page Stats fork, with commonly seen AI/training crawlers not already covered by the existing generic `bot`/`crawler`/`spider` substrings (`Google-Extended`, `GoogleOther`, `meta-externalagent`, `meta-externalfetcher`, `meta-webindexer`, `anthropic-ai`, `cohere-ai`, `Webzio-Extended`, `omgili`, `Scrapy`) - most current AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Bytespider, Amazonbot, ...) were already caught by those generic substrings. Deliberately does not include on-demand/user-triggered AI browsing agents (`ChatGPT-User`, `Claude-User`, `Perplexity-User`, etc.) - those requests are initiated by a real person's action, not an autonomous crawler; admins can add them to `bot_regexp` themselves if they'd rather treat them as bots too.

3. [](#bugfix)
    * bugfix: `log_bot`'s blueprint default (`0`/disabled) and its help text ("by default we do not log bot activity") both contradicted the actual shipped default (`log_bot: true` in `page-insights.yaml`) - bot hits have always been logged by default. Corrected the blueprint default and reworded the help text in en/de/fr to describe the actual behavior and why it matters (bot hits are tagged via `is_bot`, not skipped, which is what makes the new "Hide bots" filter possible).
    * bugfix: every `$grav['log']->addInfo()`/`addError()`/`addDebug()` call threw `Call to undefined method` on Grav 2.0 (live-verified: `Monolog\Logger::addInfo()`, from the new manual-action logging above) - those `add<Level>()` names are Monolog 1.x-only convenience aliases, removed in Monolog 2.0; Grav 1.7 bundles Monolog 1.x (so this worked there, unnoticed for years), Grav 2.0 bundles a newer major without them. Replaced every call, including three pre-existing ones unrelated to this release (page collection's error handler, the automatic-prune validation), with the plain PSR-3 method names (`->info()`/`->error()`/`->debug()`) - present with the same signature on both Monolog 1.x and 2.x/3.x, so this is a straight rename, not a version-conditional shim.

# v3.1.7
## 08/20/2026 ([6b63878](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/6b638789f7e93f188e92be0cd78b1cf2a9a78b92))

1. [](#new)
    * feat: added a "Maintain database" button next to the database-size badge on the Admin2 dashboard, opening a dialog (`window.__GRAV_DIALOGS.form()`) with a warning that deletion is permanent plus a choice of three actions - free up disk space only (VACUUM), delete orphaned events, or delete data older than 1 year - all backed by the same `Stats` methods (`vacuum()`/`pruneOrphanedEvents()`/`pruneData()`) the `prune`/`events:prune-orphans`/`vacuum` CLI commands already use. New `POST /page-insights/db/maintain` endpoint (`api.system.write`), Admin2-only (no Classic Admin equivalent, matching this plugin's Admin-Next-first convention for new features).

# v3.1.6
## 08/20/2026 ([67713d5](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/67713d5528b64e91c7cfb8de0f046740913d9c65))

1. [](#new)
    * feat: added an "HTTP Status Codes" breakdown next to Top Pages (both Admin UIs), showing the 200/404/other split of collected hits. Backed by the `data.http_code` column, which has existed unused in the schema since the very first migration - now actually populated (`Stats::collect()`) and exposed via a new `Stats::statusCodeSummary()`. Deliberately only distinguishes 200 (routable page) and 404 (`template() === 'notfound'`) - the two states reliably known at collection time - everything else (redirects, 403, etc.) folds into a fixed "other" placeholder bucket for now, kept comparable across periods/installs even when empty.

2. [](#bugfix)
    * bugfix: the Top Pages dashboard widget's column-width class was rendered as `col-{{ conf.cols_top_pages }}`, doubling the `col-` prefix already contained in every `cols_top_pages` config value (e.g. `col-col-12`) and silently dropping its Bootstrap sizing. Fixed as part of giving this widget a `col-12 col-md-9` default (paired with the new status-codes widget at `col-12 col-md-3`).
    * bugfix: the Admin2 config tab's info banner (`TAB_ADMIN2_INFO`) claimed the tab "currently has no additional settings of its own" and was "reserved for future Admin2-specific options" - stale ever since the `default_pages_scope` setting was added right below it. Reworded to match the Classic Admin tab's banner, simply stating the settings on this tab only affect Admin2 and have no effect on the classic Admin dashboard. Corrected in en/de/fr.

# v3.1.5
## 08/19/2026 ([4b02fda](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/4b02fdac03c228803571a3c7823fbed7d312c8f6))

1. [](#bugfix)
    * bugfix: the front-end "time on page" collector ping (`js/ps.js`) was recognized only by an exact match against the current `PATH_ADMIN_STATS . PATH_EVENTS_COLLECTION` path. Once that prefix changes underneath an already-rendered page - a plugin rename (as happened for `page-stats` -> `page-insights`) or any stale cache still serving old HTML - the browser keeps POSTing pings to the *previous* collector URL, which no longer matched and was logged as a real page hit instead (visible as a high-traffic, always-404 "page" like `/page-stats/event-collection` in Top Pages). Now recognized structurally (POST + path ending in the fixed collector suffix), which is resilient to future renames and base-path/language-prefix differences.

# v3.1.4
## 08/19/2026 ([88fe4b9](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/88fe4b9266ee6aaf621122e68bddd3ef86c5c8da))

1. [](#new)
    * feat: added `bin/plugin page-insights` CLI commands - `geo-db:update` (manual/scriptable equivalent of the "Update now" button), `prune --older-than=<value> [--yes] [--vacuum]` (deletes page-view data older than a relative or absolute cutoff, along with any now-orphaned events), `events:prune-orphans` (the same orphan cleanup on its own, regardless of age) and `vacuum` (reclaims disk space from deleted rows). This also delivers the "Scheduler-friendly console command" previously deferred as a follow-up alongside the prebuilt geo-db index.
    * feat: added fully automatic, opt-in scheduling for both the geo-db update and the new data pruning, via Grav's own Scheduler (`onSchedulerInitialized`, `bin/grav scheduler`) - no plugin-specific crontab entry needed. New config fields `geo_db_auto_update` (default `weekly`) and `data_auto_prune`/`data_auto_prune_older_than` (default `disabled`/`365d` - opt-in, since it deletes data). The exact weekday/day-of-month and time are intentionally not configurable: they're derived deterministically per installation (`AutoSchedule`), so many independent installations don't all cluster on the same popular time.

2. [](#bugfix)
    * bugfix: `Stats`'s database connection could be left with `PRAGMA foreign_keys` silently switched on for the remainder of a freshly-migrated connection's lifetime (the shipped migration files end with `PRAGMA foreign_keys = on;`, which leaked onto the connection that ran them) - contradicted this class's own documented assumption that foreign keys are never enforced. Never observed to affect an existing install in practice, but would have broken the new `pruneData()` on a database migrated and pruned within the same connection. Now explicitly reset after migration.

3. [](#improved)
    * improved: `geo-db:update` and the scheduled auto-update job now report both dates a geo-db index carries - the RIR snapshot's own date and when that snapshot was turned into an index file - instead of only the former. The two normally differ by roughly a day (a nightly build packages the previous day's already-published snapshot), which made a perfectly normal update look like a mismatch against what both admin dashboards already show.

# v3.1.3
## 08/17/2026 ([6343072](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/6343072742a614ebe8c77d53d1e87eaf75edda64))

1. [](#bugfix)
    * bugfix: fixed the Admin2 dashboard rendering in English regardless of the configured admin language - Admin2's `/translations` endpoint looks up plugin strings by the exact BCP47 locale code (e.g. `de-DE`), but Page Insights' language files use the short-form convention (`de.yaml`) like the rest of the Grav 1.x ecosystem/Weblate, a bucket Admin2 never reads. Plugin strings are now additionally mirrored into the BCP47 buckets at runtime, without duplicating any language files.
    * bugfix: fixed the above fix not taking effect after the first request in some environments - `grav-plugin-api` caches its whole route table via FastRoute once compiled, and the registration callback the fix's hook originally lived on then never runs again. Moved the hook to `onPluginsInitialized()`, which Grav fires unconditionally on every request.

2. [](#improved)
    * improved: the geo country index's location is now configurable (`geo_db_index_path`, default `user/data/page-insights/geo-country-index.bin`) and lives outside the plugin's own directory by default, so it survives a GPM update instead of being silently deleted along with the rest of the plugin directory on every update.
    * improved: Added DB indexes on data.route and data.date

# v3.1.2
## 08/16/2026 ([cdc233c](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/cdc233c3274e464baed2a48fa84e213e0b0dfb3c))

1. [](#new)
    * feat: added a `prebuilt` geo-db source mode (now the default) that downloads an already-built country index from a companion repo's scheduled CI build instead of parsing the ~54 MB RIR/NRO snapshot locally on every site - no more temporarily elevated `memory_limit` needed on the consuming site. Validates magic bytes and declared entry counts against the actual download size before ever touching the existing index, so a corrupt/truncated download can't clobber a working one. The existing `geo_db_source_url` raw-RIR local build remains fully supported as an explicit fallback for anyone who'd rather not depend on the companion repo.

2. [](#bugfix)
    * bugfix: fixed a fatal error when restoring `memory_limit` after a successful geo-db rebuild - `ini_set()` to a value below current memory usage is a catchable `\Error`, which was being thrown from inside a `finally` block and replacing an already-successful result, so the admin UI reported a rebuild failure even though the index file had already been written correctly. The restore is now skipped (harmlessly) whenever memory usage is still above the target value.

# v3.1.1
## 08/15/2026 ([26338f0](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/26338f0961dbc57856816bc33933f822584a0ea7))

1. [](#improved)
    * meta: removed `classes/Api/PageStatsApiController.php` and `admin-next/pages/page-stats.js`, two orphaned leftovers from the Page Stats → Page Insights rename that were never deleted and are no longer referenced anywhere - `PageInsightsApiController.php`/`page-insights.js` have been the active files since the fork.

# v3.1.0
## 08/15/2026 ([000098b](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/000098b7419c55711c15d5aba57785fe4a4aa67c))

1. [](#new)
    * feat: replaced the `ip2location/ip2location-php` dependency and the committed ~47 MB `IP2LOCATION-LITE-DB3.BIN` with a self-built, country-only IP lookup sourced directly from the five Regional Internet Registries' public delegated-stats data (via the RIPE-NCC/nro-delegated-stats project). The database is no longer shipped in the release archive or the repository at all - it's built on demand by an explicit admin action, keeping the plugin's own daily-refresh cadence independent of any third party's licensing or release schedule. This also resolves an unnoticed licensing issue: IP2Location LITE's terms prohibit "third party database repository" redistribution, which a public mirrored git repo effectively was.
    * feat: the geo index rebuild trigger now lives on the Page Insights dashboard itself, next to "Top countries" (rather than in the config form, since triggering a rebuild is an action, not a setting) - a status/button widget in Classic Admin (server-rendered, nonce-protected self-post form) and in Admin2 (calls the existing REST endpoints, degrades cleanly if `grav-plugin-api` isn't installed). The geo database's source URL remains configurable under Config > General.

2. [](#bugfix)
    * bugfix: fixed a fatal `Allowed memory size ... exhausted` error when rebuilding the geo index against the real, multi-hundred-thousand-line RIR source file (only ever tested before against a small fixture) - `RirStatsParser` no longer materializes a full array of every line via `preg_split()`, and the rebuild now temporarily raises `memory_limit` for the duration of the download-parse-build pipeline.
    * bugfix: `datetime_offset`'s validation pattern in `blueprints.yaml` used a POSIX character class (`[[:digit:]]`), valid in PHP/PCRE but not in JavaScript - this threw an "Invalid regular expression" console error in Admin2 on every admin page using this blueprint. Replaced with `\d`, valid in both.

3. [](#improved)
    * meta: fixed `.forgejo/workflows/lint.yml` silently skipping all language-file checks after a force-push - it diffed against `github.event.before`, which becomes unreachable once history is rewritten, and swallowed the resulting `git diff` failure with `|| true`. Now falls back to a root-commit comparison (matching the existing empty/null-SHA fallback) and no longer hides genuine failures.

# v3.0.1
## 08/14/2026 ([612054b](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/612054b0ab5dee3149048a2677db8ce420103dfd))

1. [](#bugfix)
    * Fixed "Recently viewed pages" and other date-filtered views silently excluding the most recent hits due to a timezone-offset mismatch between stored timestamps and the dashboard's UTC-based date range
    * Stopped overwriting `$_SERVER['REMOTE_ADDR']` with client-supplied proxy headers (`CF-Connecting-IP`/`Client-IP`/`X-Forwarded-For`); these are now opt-in via a new `trust_proxy_headers` setting (default off), and the resolved IP no longer leaks into the rest of the request
    * Fixed stored-XSS in the Admin2 dashboard: `_esc()` now escapes `"`/`'` in addition to `&`/`<`/`>`, and `href` values built from `encodeURIComponent()` (which does not escape `'`) are now HTML-escaped too, closing both routes by which a crafted page route or username could break out of a `title`/`href` attribute
    * Bounded the unauthenticated `/event-collection` endpoint: `session_id` must now reference an existing hit, events are capped per session, and `event`/`value` length is limited - previously anyone could insert rows indefinitely
    * Corrected `dependencies` in `blueprints.yaml` (was `>=1.6.0`, now `>=1.7.0`) so GPM no longer offers this plugin to Grav/PHP versions it actually requires (PHP 8.0+, via `str_contains()`)

2. [](#improved)
    * Release tags are now bare semantic versions (`3.0.0`) instead of `v`-prefixed, matching Grav's GPM convention for version sorting and `releases/latest`

# v3.0.0
## 08/13/2026 ([e9abfd2](https://codeberg.org/chschmidt/grav-plugin-page-insights/commit/e9abfd245f1c6fb84b1296bdeb652a05bb5ba404))

> First release under the name **Page Insights**. Forked from
> [Page Stats](https://github.com/francodacosta/grav-plugin-page-stats) by Nuno Costa after several years without
> direct maintainer activity; see README for the full history. Feature-wise this corresponds to what was also
> contributed upstream as Page Stats PR #56 (later merged) - development continues independently here from this
> point, under a new name.

1. [](#new)
    * feat: translations are now managed via [Codeberg Translate](https://translate.codeberg.org/engage/grav-plugin-page-insights/) (Weblate) - German and French translations added, alongside the English source strings.
    * feat: "Recently viewed pages" can now be filtered to only real, filesystem-based content pages under `user/pages` (`?scope=real`), hiding hits against assets, `sitemap.xml`, `robots.txt`, and similar non-page routes. The route whitelist is built from Grav's own `Pages` object, cached and keyed to `Pages::getPagesCacheId()` so it invalidates automatically whenever page content changes. `Stats::query()`'s `$params` filter mechanism was extended to accept array values, building an `IN (...)` clause instead of a plain equality check (backward compatible; an empty array yields an intentionally unsatisfiable `1 = 0` rather than silently returning everything). A new `default_pages_scope` blueprint option (Grav 2.0 / Admin2 tab) sets which scope the dashboard shows on first load.
1. [](#bugfix)
    * bugfix: fixed a `Class "Grav\Plugin\PageInsights\Stats" not found` fatal error on a fresh install/clone. The compiled Composer autoloader (`vendor/composer/autoload_*.php`) still referenced the pre-rename `Grav\Plugin\PageStats` namespace and had never been regenerated after the switch to `Grav\Plugin\PageInsights` - `composer.json` itself was correct. Fixed via `composer dump-autoload`; a note was added to `CONTRIBUTING.md` so this doesn't recur after future namespace/file changes.
    * bugfix: `composer.json` declared a minimum PHP version of `>=7.1.3`, but `Stats::query()` uses `str_contains()`, which requires PHP 8.0 and has no polyfill in the production dependencies - raised the requirement to `>=8.0`.
    * fix: corrected a non-existent maintainer contact address (`info@jcs-net.de`) in `blueprints.yaml`.
    * bugfix: the new "Real pages only" filter always returned no data, from two independent causes found while diagnosing on production. First, the API controller looked up the running plugin instance via `$grav['page-insights']`, which isn't a thing - Grav never registers plugin instances into the DI container under their slug, only internally by class name. Switched to Grav's own `Plugins::getPlugin('page-insights')` static helper, which iterates loaded plugins matching on `->name`. Second, even with the plugin instance found, the route whitelist still came back empty: Admin2/API requests run with `Pages::disablePages()` already applied by Grav's own admin/API layer (for performance, since most backend requests don't need the full frontend page tree), which makes `Pages::init()` skip `buildPages()` entirely - so `routes()`/`getPagesCacheId()` stayed silently empty, with nothing to catch. `Pages::enablePages()` is Grav's own documented counterpart for exactly this situation.
    * bugfix: `vendor/composer/autoload_real.php` declared the same `ComposerAutoloaderInit<hash>` class as Page Stats' own `vendor/autoload.php` - inherited unchanged from the full `vendor/` copy made during the technical rename, and silently carried forward by every subsequent `composer dump-autoload` since (Composer reuses the suffix already present in `vendor/autoload.php` instead of generating a fresh one, specifically to avoid diff noise across routine installs/updates). This only surfaced once Page Insights was installed on production alongside Page Stats for the first time - Grav's plugin loader requires both plugins' `vendor/autoload.php` in the same PHP process, and the second one to load fataled with `Cannot redeclare class`. Fixed with an explicit, unique `config.autoloader-suffix` in `composer.json`.
1. [](#improvements)
    * meta: added Christian Schmidt as a second `composer.json` author and as a second copyright holder in `LICENSE`, alongside Nuno Costa as original author.
    * meta: removed five vendor packages (`twig/twig`, `twig/intl-extra`, `symfony/intl`, `symfony/polyfill-intl-icu`, `symfony/polyfill-ctype`, `symfony/polyfill-php72`) left over from an earlier dependency tree and no longer listed in `composer.lock`; refreshed the lock file's stale content-hash.
    * meta: split the single `languages.yaml` into per-language files under `languages/` (`en`/`de`/`fr`) to enable translation via Codeberg Translate; corrected a few remaining "Page Stats" branding leftovers in the English source strings and the French translation.
    * meta: added `CODE_OF_CONDUCT.md`, `SECURITY.md`, and `CONTRIBUTING.md`, plus issue/PR templates for both Codeberg and the GitHub mirror.
    * meta: removed the unused `marcocesarato/php-conventional-changelog` dev dependency (another Nuno Costa leftover, never actually used to generate this project's changelog) and its full dependency tree (7 packages: `psr/container`, `symfony/console`, three Symfony polyfills) - nothing else in `vendor/` depended on any of them.

# v2.9.0
## 08/10/2026 ([ab30321](https://github.com/francodacosta/grav-plugin-page-stats/commit/ab30321b04aaf8e835303876dbec2b52dc9f28b0))

1. [](#new)
    * feat: Page Detail / User Detail sub-views. Since Admin2's client-side router only supports a single dynamic path segment per plugin page (no catch-all), sub-views are addressed via query parameters on the fixed plugin route (`?view=page-detail&route=...`, `?view=user-detail&user=...`/`?ip=...`), driven by plain `history.pushState()`/`popstate` - verified to survive a hard reload on all three URL forms and to work correctly with the browser back button. Page Detail shows KPIs, a time-series chart, top countries/browsers/platforms and recent views for a single route; User Detail shows KPIs, a time-series chart, that user's top pages (linked to Page Detail), and their recent views. Both are assembled entirely from the existing dashboard building blocks, no new rendering code. Linked from "Recently viewed pages", "Top users", and both lookup result tables via a small trend icon.
    * feat: `blueprints.yaml` reorganized into three tabs - General (settings shared by both admin UIs), Grav 1.7 / Classic Admin (the existing per-widget settings, still actively used by the classic templates), and Grav 2.0 / Admin2 (currently an info placeholder for future Admin2-specific options) - keeps the config navigable as both admin UIs continue to be maintained side by side.
    * feat: time-series trend charts for page views / unique visitors / unique users, and country flags (via flagcdn.com) in the Top Countries widget.
    * feat: "Recently viewed pages" load-more pagination in 10-row increments (no offset/cursor, avoiding duplicate or missing rows when new hits land between clicks), with Browser and Platform columns added.
    * feat: IP fallback instead of a plain "(anonymous)" label for hits without a logged-in user, individually traceable via User Detail using `ip` as an alternate key to `user`.
1. [](#bugfix)
    * bugfix: `type: section` blueprint fields require a `title` to render in Admin2 at all (unlike Classic Admin, which renders `text` alone) - several passes fixing empty/invisible info sections in the Admin2 config screen, plus a missing border on empty info boxes.
    * bugfix: the `collector_ping_interval` blueprint field existed but wasn't actually exposed as an editable field.
    * bugfix: link ordering and missing/broken links across the recent-pages, lookup, and new detail views, ironed out over several iterations as the detail views took shape.
    * bugfix: "Load more" button CSS on the recently-viewed-pages list.
    * bugfix: display error on the page-view detail row.
1. [](#improvements)
    * improvement: database size now shown with a clear label, moved out of an oversized standalone KPI tile into a more compact placement.
    * meta: renamed the internal namespace/classes (`Grav\Plugin\PageStats` → `Grav\Plugin\PageInsights`), REST route prefix and Admin2 route (`/page-stats` → `/page-insights`), config namespace, and translation key prefix throughout. Changed the default database filename to `user/data/page-insights.sqlite` (was `user/data/page-data.sqlite`) so a parallel Page Stats installation isn't affected during a transition - see README for details. The page front-matter opt-out key also changed from `page-stats:` to `page-insights:`.

# v2.8.0
## 08/10/2026 ([afeb7de](https://github.com/francodacosta/grav-plugin-page-stats/commit/afeb7de794c3bb6c6477f80f3a9daeef219943ae))

1. [](#new)
    * feat: Grav 2.0 / Admin2 compatibility (#53) - adds a REST API controller (`classes/Api/PageStatsApiController.php`) exposing the existing `Stats` data layer, and an Admin2 sidebar entry + single-page dashboard (`admin-next/pages/page-stats.js`) with page-view/visitor/user totals, top pages/countries/browsers/platforms/users, recently viewed pages, and page/user lookup. Consolidates the nine separate classic-admin pages into one dashboard, since Admin2 component pages are a single route. Requires a `compatibility` declaration in `blueprints.yaml`, which is also what the Grav 2.0 migration wizard checks - its absence is why older versions were auto-flagged as incompatible. Classic Admin (Grav < 2.0) keeps working unchanged via the existing `onAdminDashboard`/`onAdminPage` hooks.
1. [](#bugfix)
    * bugfix: `getUserIP()` returned `null` in request contexts without a real client IP (e.g. `bin/grav page-system-validator`, which still fires `onPageInitialized`), and `isEnabledForIp(string $ip)` declared a non-nullable parameter - causing an uncaught `TypeError` and a fatal error. `getUserIP()` now returns `?string` and `isEnabledForIp()` accepts `?string`, treating "no IP" as "nothing to log" instead of crashing.
    * bugfix: `Stats::query()` mishandled date-range filtering whenever both `$dateFrom` and `$dateTo` were passed - the `date_from`/`date_to` values were both used to build the `BETWEEN` clause *and* re-processed by the generic equality-filter loop, producing an invalid `date_from = :date_from` condition (`data` has a `date` column, not `date_from`) and a `SQLSTATE[HY000]: no such column: date_from` error. The `BETWEEN` clause itself also had a placeholder typo (`:dateTo` instead of `:date_to`), and `DateTimeImmutable` objects were bound directly instead of as formatted strings. This is likely why date-range filtering across the plugin has been effectively dead code for years - it seems to have never been exercised with both bounds set at once.
    * bugfix: `Stats::siteSummary()` called `query()` with the wrong argument order (missing the `$limit` argument), so `$dateFrom` landed in the `?int $limit` slot - a `DateTimeImmutable` object where an `int` was expected - and would fatal with a `TypeError` whenever a date range was actually supplied.
    * bugfix: `Stats::pagesSummary()`'s SQL was missing `%where` entirely, so bindings for the date range (or query params) had nothing to attach to and SQLite rejected them with `SQLSTATE[HY000]: column index out of range`. Added the missing `%where` so date-range filtering on the "top pages" list actually works.
1. [](#improvements)
    * improvement: `Stats::totalUniqueVisitors()` / `Stats::totalUniqueUsers()` helpers for the new Admin2 overview KPIs
    * improvement: `Stats::query()` now only binds a parameter if its placeholder is actually present in the final SQL string, as a defensive safety net against further query-builder/SQL mismatches like the `pagesSummary()` one above
    * improvement: the SQLite connection now sets `PRAGMA busy_timeout` (5s) and `PRAGMA journal_mode = WAL`. Without these, concurrent requests writing to the stats database serialize on a single file lock (default `busy_timeout` is 0) and every commit fsyncs the whole rollback journal; under real traffic this can make individual requests noticeably slower and, in the worst case, pile up PHP-FPM workers waiting on the same lock until the pool is exhausted - taking down unrelated requests too, not just page-stats.

# v2.5.3
## 09/01/2023

1. [](#bugfix)
    * fix: Undefined index: HTTP_USER_AGENT (#32)

# v2.5.2
## 09/01/2023

1. [](#bugfix)
    * dummy release to correct typo in release versions (#33)

# v2.5.1
## 05/01/2023

1. [](#bugfix)
    * click on view all on recently pages viewed will now show you a list of recently viewed pages grouped by date

# v2.5.0
## 26/09/2022

1. [](#new)
    * You can noe define a list of user agents to classify as bots/crawlers

# v2.4.1
## 21/09/2022

1. [](#bugfix)
    * Add missing translation strings

# v2.4.0
## 20/09/2022

1. [](#new)
    * configuration option to show page title our route
1. [](#bugfix)
    * don't error out if ip2location lib throws exception when geolocating ip
    * add debug message with IP on error
1. [](#improvements)
    * page stats now shows details about all page views, not only the last 10 views
    * time on page collect metrics once a second until the first `ping interval` value you specified and then every `ping interval` seconds, this is so that initial time on page is more accurate

# v2.3.0
## 5/09/2022

1. [](#new)
    * Show user agent on user detail page
1. [](#bugfix)
    * Top country pages not showing

# v2.2.0
## 31/08/2022

1. [](#new)
    * Front End event collection support
    * time on page
    * top browsers as table or pie chart
    * top platforms as table or pie chart
    * top countries as table or pie chart
    * View stats for all countries
    * View stats for all browsers
    * View stats for all platforms
    * View stats for all users
    * recently viewed page has link to user stats page
    * list of urls to exclude from processing
1. [](#bugfix)
    * fix error message when http_referer is not set
    *  do not include FE tracker is not enabled for that page
    * page stats widget not displaying on main dashboard page if url does not end in `/dasboard`
1. [](#improvements)
    * moved sidebar menu entry to bottom of list
    * top users only shows user name, page views are shown on hover

# v2.1.0
## 27/08/2022

1. [](#new)
    * Log http referer ([65a006](https://github.com/francodacosta/grav-plugin-page-stats/commit/65a0060c4ff55646e9c7eec32ba14109a30b7fa2))
    * Show page view widget instead of grav default one ([3361fd](https://github.com/francodacosta/grav-plugin-page-stats/commit/3361fd39e69ce0e7b96c438808370664e5b87667))
1. [](#bugfix)
    * Db file and size not were shown after refactoring ([bb5e07](https://github.com/francodacosta/grav-plugin-page-stats/commit/bb5e0748120bf0ab985738520ea8dceac377c2fb))
    * Migrate was not detecting version properly if migration happened at same time ([5691a5](https://github.com/francodacosta/grav-plugin-page-stats/commit/5691a5d3fae4f1ddc855266befecc4e5774aa509))
    * fix typo in plugin settings translation keys #20
    * fix typo in geolocation #21
    * fix Platform and browser column labels were switched #22

# v2.0.0
## 27/08/2022

1. [](#new)
    * ⚠ BREAKING CHANGES = Using bin geolocation db ([54b6a9](https://github.com/francodacosta/grav-plugin-page-stats/commit/54b6a9e40e6b8c4ff8ad66d4aa3632d90635b843))
    * Show all pages on most recent ([3c0784](https://github.com/francodacosta/grav-plugin-page-stats/commit/3c07842e1be491f99dce7b0264167417f0af0c20))
    * View all pages ([7cf039](https://github.com/francodacosta/grav-plugin-page-stats/commit/7cf0396f451896c16b7d4fdd80224fbac81fb416))
1. [](#bugfix)
    * No limit on all pages ([cf3513](https://github.com/francodacosta/grav-plugin-page-stats/commit/cf3513c47ff25c748c1a09324284fd05e4840444))

# v1.10.0
## 16/08/2022

1. [](#new)
    * Show all pages on most recent ([3c0784](https://github.com/francodacosta/grav-plugin-page-stats/commit/3c07842e1be491f99dce7b0264167417f0af0c20))
    * View all pages ([7cf039](https://github.com/francodacosta/grav-plugin-page-stats/commit/7cf0396f451896c16b7d4fdd80224fbac81fb416))

# v1.9.3
## 18/08/2022

1. [](#bugfix)
    * Display date only on user detail page ([b4fb45](https://github.com/francodacosta/grav-plugin-page-stats/commit/b4fb4537ce87a44a31246ea878e170009841c48c))

# v1.9.2
## 16/08/2022

1. [](#bugfix)
    * Show correct day instead of current day in user details recent page views ([bf1329](https://github.com/francodacosta/grav-plugin-page-stats/commit/bf13292f1f152efbea1d9bccc2320a740b37673d))

# v1.9.1
## 16/08/2022

1. [](#bugfix)
    * Removed user name from recently viewed pages of user details screen ([13e612](https://github.com/francodacosta/grav-plugin-page-stats/commit/13e6123d4369225b93ea6d4196a55a8286476ffa))

# v1.9.0
## 16/08/2022

1. [](#new)
    * Group page views by day in user detail page ([184489](https://github.com/francodacosta/grav-plugin-page-stats/commit/1844899445f7d6c894720214c32225f8e2d57bf2))

# v1.8.2
## 15/08/2022

1. [](#bugfix)
    * Show platform data on user detail page ([d510cd](https://github.com/francodacosta/grav-plugin-page-stats/commit/d510cd38a5a3d6a36cd009946286cf418a3cbdb5))

# v1.8.1
## 15/08/2022

1. [](#bugfix)
    * Better user vs ip detection ([9d2216](https://github.com/francodacosta/grav-plugin-page-stats/commit/9d2216bc98bda86cdfea6c23104739d39b25f79e))

# v1.8.0
## 11/08/2022

1. [](#new)
    * Show location on recent views of page details ([ea7d29](https://github.com/francodacosta/grav-plugin-page-stats/commit/ea7d290aae6dd783a570c50604835bd6d18adeac))
    * User Details Page ([91fed4](https://github.com/francodacosta/grav-plugin-page-stats/commit/91fed4f7702bb47a5ca132ec60472eb2f0719c88))

# v1.7.0
## 11/08/2022

1. [](#new)
    * add stats icon to pages listing, so when you click on the stats icon you go to the stats page
    * Show paltform and browser on recently viewed pages
    * Show recent views on page details stats

# v1.6.1
## 05/08/2022

1. [](#new)
   * Click on icon to open page in new tab ([701820](https://github.com/francodacosta/grav-plugin-page-stats/commit/7018206c7986ad2c2322e88c3a37b01c9698c437))
2. [](#bugfix)
    * Error with double $$; make migrations more forgiving if updating from dev version (pre migrations) ([90a027](https://github.com/francodacosta/grav-plugin-page-stats/commit/90a027f94174549fe6529b7ec60b8dff7f87575d))

# v1.6.0
## 05/08/2022

1. [](#bugfix)
    * Fixed wrong labelled plugin setting ([6f8ca2](https://github.com/francodacosta/grav-plugin-page-stats/commit/6f8ca29443bf42685ffc85bed9b821c9f6153910))

# v1.5.0
## 05/08/2022

1. [](#new)
   * Click on icon to open page in new tab ([701820](https://github.com/francodacosta/grav-plugin-page-stats/commit/7018206c7986ad2c2322e88c3a37b01c9698c437))

# v1.4.0
## 05/08/2022

1. [](#new)
    * Detailed page stats ([7672be](https://github.com/francodacosta/grav-plugin-page-stats/commit/7672bee5ac9b9f54dc5735ab407d455d0d7b8b9b))

# v1.3.0
## 05/08/2022

1. [](#new)
    * Collect browser stats ([97e89f](https://github.com/francodacosta/grav-plugin-page-stats/commit/97e89f30f096d9ccc4becab1037c851edb1e1577))
    * Display route instead of title ([2feb0e](https://github.com/francodacosta/grav-plugin-page-stats/commit/2feb0ef862a08af027e846cb4390e0a209ba991b))
    * Setting to throw exception on errors, or ignore them (log them) ([6a4bd2](https://github.com/francodacosta/grav-plugin-page-stats/commit/6a4bd2e9c80b8e2ba3ee615d761f69a95423a502))
    * Top countries ([1d2913](https://github.com/francodacosta/grav-plugin-page-stats/commit/1d29130f0d589f66c07769e98387d697fb5d0724))

# v1.2.0
## 05/08/2022

1. [](#new)
    * Configure viewed pages ([b3a0b2](https://github.com/francodacosta/grav-plugin-page-stats/commit/b3a0b28cbb282f6b173d3166ceb0f889ab4dd0de))
    * Select widget size [#17](https://github.com/francodacosta/grav-plugin-page-stats/issues/17) ([4f01da](https://github.com/francodacosta/grav-plugin-page-stats/commit/4f01da6d19db2253ff015f064a6ce477c0577e17))
    * Specify number of top users to fetch ([c55a01](https://github.com/francodacosta/grav-plugin-page-stats/commit/c55a01b22f6c2ec36e696d537f83de50ad40cf21))
    * Toggle page views widget [#14](https://github.com/francodacosta/grav-plugin-page-stats/issues/14) ([686085](https://github.com/francodacosta/grav-plugin-page-stats/commit/6860859c60d478a3f2c68dbc22d698f04ec042e3))
    * Toggle unique visitors [#15](https://github.com/francodacosta/grav-plugin-page-stats/issues/15) ([93fde2](https://github.com/francodacosta/grav-plugin-page-stats/commit/93fde20f6a953841cbcc4b600754631885829ce0))
    * Top pages: toggle display, configure size and records to show ([65be4c](https://github.com/francodacosta/grav-plugin-page-stats/commit/65be4c89701a53ab4da12782815682196fae9d8c))

# v1.1.0
## 04/08/2022

1. [](#new)
    * Exclude ips from processinf [#5](https://github.com/francodacosta/grav-plugin-page-stats/issues/5) ([c07ea4](https://github.com/francodacosta/grav-plugin-page-stats/commit/c07ea4e71c1f026bc0c0c3b884b1c56777e29ed3))
    * Recreate geolocation db from zip file ([3ed6e8](https://github.com/francodacosta/grav-plugin-page-stats/commit/3ed6e8934ae02d95e518b3b1a137e0a390ece255))
    * Toggle plugin from front matter [#6](https://github.com/francodacosta/grav-plugin-page-stats/issues/6) ([bd65ca](https://github.com/francodacosta/grav-plugin-page-stats/commit/bd65ca388cdb53c641796f0b993fc615ec71681b))
    * Toggle unique users widgets ([3196bc](https://github.com/francodacosta/grav-plugin-page-stats/commit/3196bcc6968d83eae3d6da2c5e3f4423c4ff71f6))
    * Top users ([4e18f7](https://github.com/francodacosta/grav-plugin-page-stats/commit/4e18f7fef961e64285d990759aa7ad47eccdd31d))
2. [](#bugfix)
    * #1 non standard admin page [#1](https://github.com/francodacosta/grav-plugin-page-stats/issues/1) ([5de885](https://github.com/francodacosta/grav-plugin-page-stats/commit/5de885359789fb0751e255a819dd8b6d19eb3a8e))
    * Fixed plugin links in blueprints ([ab5474](https://github.com/francodacosta/grav-plugin-page-stats/commit/ab5474cc01513492fc38696fdd04a934cfbe682a))
    * Remove weirdly named folder ([ff3707](https://github.com/francodacosta/grav-plugin-page-stats/commit/ff37078fdce36fb982fb23f2749344c31595e609))
    * Unique users ([428004](https://github.com/francodacosta/grav-plugin-page-stats/commit/428004a9c1731faa98e3147580d4b42488eaddfd))
