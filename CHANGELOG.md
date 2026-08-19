# v3.1.4
## 08/19/2026

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
