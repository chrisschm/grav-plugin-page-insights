# Notable Past Bugs

This document is a numbered, chronological-ish list of non-obvious bugs found and fixed in this
plugin's history, together with their root cause and the reasoning behind the fix - useful context
before touching related code, since several of these are the kind of thing that's easy to
reintroduce by accident. It does **not** cover current architecture, schema, or design goals -
those stay in [`ARCHITECTURE.md`](ARCHITECTURE.md) and [`DATABASES.md`](DATABASES.md), which this
file links back to rather than duplicating. *(Eine deutsche Kurzfassung findest du am Ende dieser
Datei.)*

Entries 1-21 are in roughly the order they were found; entries 22 onward were folded in later from
session working notes during a documentation consolidation pass and are appended rather than
interleaved, so cross-references between existing entries (e.g. "see bug #12 above") keep working
- don't renumber this list without checking for such references first.

1. **`Class "Grav\Plugin\PageInsights\Stats" not found`** on a fresh clone, `composer.json`
   itself correct. Cause: the compiled autoloader still referenced the pre-rename
   `Grav\Plugin\PageStats` namespace from before the Page Stats -> Page Insights rename, and
   `composer dump-autoload` had never been re-run/committed afterwards. See "Composer & the
   compiled autoloader" in `ARCHITECTURE.md`.
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
   dashboard-integrated trigger described in `GEOLOCATION.md`, which works without that
   dependency.
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
9. **The old `IP2LOCATION-LITE-DB3.BIN` removed in 2026-08-15 (see `GEOLOCATION.md`) was still
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
    into the BCP47 buckets at runtime - see "Admin2 i18n" in `ADMIN-UI.md` and
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

14. **`$grav['log']->addInfo()`/`addError()`/`addDebug()` throw `Call to undefined method` on
    Grav 2.0** (`Monolog\Logger::addInfo()` in the actual crash, live-verified against a Grav 2.0
    test environment - `PageInsightsApiController::rebuildGeoDb()`, freshly introduced that
    session). Cause: those `add<Level>()` names are Monolog 1.x-only convenience aliases for the
    real PSR-3 `LoggerInterface` methods (`debug()`/`info()`/`error()`/etc.) - present in Monolog
    1.x alongside the PSR-3 names, removed entirely by Monolog 2.0. Grav 1.7 bundles Monolog 1.x
    (so `addError()` etc. happened to work there, which is how this went unnoticed for years - see
    the pre-existing `addDebug()`/`addError()` calls in `collectPageData()`'s catch block and
    `registerAutoPruneJob()`'s validation, both from well before this plugin's Grav 2.0 support),
    Grav 2.0 bundles a newer Monolog major without them. Fix: use the plain PSR-3 method names
    everywhere (`->info(...)`, `->error(...)`, `->debug(...)`) - confirmed present, with the same
    single-string-message signature, on both Monolog 1.0.0 and 3.10.0, so this is a straight
    rename with no Grav-version branching needed, not a compatibility shim.

15. **Dashboard rendering got noticeably slower as the "data" table grew, traced to the date-range
    filter defeating its own index.** `Stats::query()`'s date-range filter has always compared
    `datetime(date) BETWEEN datetime(:from) AND datetime(:to)` (see "Notable past bugs" #4/#5's
    history and `DATABASES.md`, "Date storage and comparison") - correct for correctness, but
    `idx_data_date` (migration 5) is built on the raw `date` column, and SQLite's planner won't match
    a plain-column index against a query that wraps the column in a function call. Confirmed via
    `EXPLAIN QUERY PLAN` against a realistically sized test database: every date-range-filtered query
    - by 2026-08 nearly every dashboard widget, and now also every widget the dashboard-wide "Hide
    bots" filter touches - was doing a full table `SCAN` regardless of the index, on every request.
    Fixed with a second, *expression* index on the exact same expression the WHERE clause uses
    (`idx_data_date_normalized ON data (datetime(date))`, migration 6 - see `DATABASES.md`), which
    SQLite can match; `idx_data_date` stays for `recentPages()`'s unfiltered `ORDER BY date DESC
    LIMIT n`, a query shape the expression index doesn't help. Same investigation also found
    `topCountries()`/`topBrowsers()`/`topPlatforms()`/`statusCodeSummary()` each firing a second,
    entirely redundant `totalPageViews()` query - with the identical `%where`/date-range filter -
    purely to compute the "share" percentage's denominator, even though each method's own (now
    unlimited instead of `LIMIT`-ed) `GROUP BY` result already contains every group needed to sum
    that same total in PHP. A reminder that a documented, deliberate correctness fix (the
    `datetime()` wrapping) can quietly defeat an otherwise-correct index years later, and that
    "verify with `EXPLAIN QUERY PLAN` against a realistic row count," not just re-reading the SQL, is
    what actually catches it.
16. **`events` had no index at all, and Classic Admin's "Recently viewed pages" widget queries it
    once per displayed row.** `recently-viewed-pages.html.twig` calls `pageStats.db.timeOnPage(s.id)`
    inside its per-row loop - each call filters `events` by `session_id` with no supporting index, so
    every displayed row triggered its own full table `SCAN` of `events`; the dedicated "view last
    1000 pages" page could mean up to 1000 of those on a single request. Fixed with
    `idx_events_session_id` (migration 6). Left as a per-row indexed lookup rather than rewritten
    into one batched query across the whole widget - the index alone turns each call into a cheap
    indexed `SEARCH`, and batching would mean threading a precomputed session-id -> seconds map
    through all three callers of this shared widget (`stats.html.twig`, `page-details.html.twig`,
    `user-details.html.twig`) for a benefit an in-process SQLite index lookup no longer meaningfully
    adds to.
17. **Classic Admin's "Recently viewed pages" day-group headers always rendered in English,
    regardless of the admin's configured language** - not previously documented as a bug, found
    while fixing the same underlying problem for Admin2's chart x-axis labels (see "Localized date
    formatting" in `ADMIN-UI.md`). `recently-viewed-pages.html.twig` used Twig's built-in
    `|date('F jS')` filter, which wraps plain PHP `date()`/`DateTime::format()` - never locale-aware
    for named month/day formats, regardless of the site's or admin's language. Replaced with a new
    `page_insights_localized_day` filter (`classes/LocalizedDate.php`,
    `PageInsightsPlugin::onTwigExtensions()`) using `IntlDateFormatter` when available. A reminder
    that Twig's/PHP's own `date()`-style formatting functions are never a shortcut to a localized
    date, no matter how innocuous a change nearby (or, as in this case, on an entirely different
    admin UI) makes it look.
18. **Two more date displays turned out to have no formatting at all, not just the wrong one** -
    found by live-testing bug #17's fix against real Grav 1.7 and 2.0 test instances, not from
    reading the code alone. Classic Admin's three dashboard chart widgets
    (`widgets/page-views.html.twig`/`unique-visitors.html.twig`/`unique-users.html.twig`) fed a bare
    `x: "{{ s.date }}"` (a raw `YYYY-MM-DD` string, e.g. `2026-07-25`) straight into Chart.js as an
    x-axis label; Admin2's every "recently viewed"-style table (dashboard, Page/User Detail, Page/
    User search) concatenated the equally raw `day`/`time` fields from `Stats::recentPages()` with
    no formatting either. Both fixed alongside #17 - see "Localized date formatting" in
    `ADMIN-UI.md` (`page_insights_short_day` Twig filter / `_formatRecentDate()`). A reminder that
    "still shows an obviously-a-string date" is a different, easier-to-spot-once-you-look failure
    mode than "shows the wrong language" (bug #17's kind), and one that's easy to miss by code
    review alone if nothing nearby calls attention to that specific line - a live check against a
    real admin instance, in a real non-English admin language, is what actually caught both.
19. **An early version of `pagesSummary()`'s rollup fast path (see `DATABASES.md`, "Rollups")
    silently overcounted "hits" by several percent on every call.** It summed the *entire* first and
    last rollup day covered by `[$dateFrom, $dateTo]`, on the unstated assumption that both would
    always land exactly on a day boundary - true for a UI date picker that only ever offers whole
    days, false for the benchmark harness's `now->modify('-30 days')` (an arbitrary instant partway
    through a day) and for any other caller that isn't guaranteed to pass midnight-aligned bounds.
    The extra hours before `$dateFrom` on the first rollup day got summed in anyway. Never showed up
    in the `EXPLAIN QUERY PLAN`/timing work (both are blind to whether a *correct* query returns the
    *right numbers*) - only caught by writing a second script that ran the new rollup-backed method
    and the original live query against the same synthetic database and diffing their results
    row-by-row, which is what turned up a consistent several-percent-too-high count instead of an
    exact match. Fixed by never serving the first/last calendar day from the rollup at all,
    regardless of whether they happen to be fully covered - see the method's docblock. A reminder
    that a performance fix that returns plausible-looking, consistently-in-the-same-direction wrong
    numbers is more dangerous than one that crashes outright - it wouldn't have been visually obvious
    on a real dashboard either, just quietly-inflated totals.
20. **Two real, `git pull`-updated installations had been silently stuck on an old schema for
    weeks**, discovered while rolling out migration 7 (see #19 above) - `bin/plugin page-insights
    rollup:build` failed with `no such table: rollup_daily` on both, and a manual
    `SELECT version FROM migrations ORDER BY id DESC LIMIT 1` on one of them showed the last-recorded
    migration was version 5's *indexes*, not migration 7. Root cause: `Stats::migrate()`'s trigger on
    an already-existing database was, until this fix, `data/migrations/MUST_MIGRATE` existing on
    disk alone - a file shipped tracked in git and `unlink()`-ed by `migrate()` once it succeeds.
    That's reliable for any deployment method that replaces the whole plugin directory wholesale
    (GPM download, tarball extraction - the flag's tracked content reappears with everything else),
    but not for `git pull`: a pull only applies the diff between commits for each path, and the
    flag's tracked *content* never actually changes between releases, so the local deletion that
    happens the very first time `migrate()` ever runs on an installation has nothing to "pull back" -
    it stays deleted forever, and every migration shipped after that first one silently never runs
    again. Confirmed in practice: migration 6's `idx_data_date_normalized` expression index (see
    "Indexes" in `DATABASES.md`, and bug #15 above it - the fix this exact index was built for) had
    never actually been applied on either installation - see bug #15 for the dashboard-slowdown it
    was built to fix - meaning that fix was never actually live in production, silently, for as long
    as those installations had been running `git pull` updates. A missing index doesn't throw an
    error, it just makes a query slow -
    nothing surfaced this until a *table*, not just an index, turned out missing too. One of the two
    installations had a second, unrelated problem compounding this: its `migrations` table's last row
    recorded `version = 1` instead of `5` (a stale manual backfill from an earlier test-environment
    reset, all rows sharing one identical timestamp rather than the different times real sequential
    migrations would have) - `ORDER BY id DESC LIMIT 1` trusted that wrong value, so `migrate()`
    tried to re-run migration 2 against a database whose columns already existed, failing with
    `duplicate column name`. Fixed with two independent changes: `Stats::__construct()` now also
    calls a new `hasPendingMigrations()`, comparing the highest `data/migrations/N.sql` on disk
    against the recorded version, and triggers `migrate()` on that alone, regardless of whether
    `MUST_MIGRATE` exists - self-healing under any deployment method, not just whole-directory
    replacement (see "Migrations" in `DATABASES.md`); and the incorrect `migrations` row was manually
    deleted (not "corrected" to `5` - migration 5 demonstrably never ran, so marking it done would
    have left the index permanently missing) on the one affected installation, restoring it to a
    truthful state before letting `migrate()` catch up normally. A reminder that a mechanism proven
    correct under the deployment method it was designed and tested against (see bug #12 above for a
    similar "worked as designed for the tested case" gap) can still be silently wrong under a
    different, equally normal one nobody happened to test it against - and that a *missing* piece of
    schema is a strictly quieter failure than a *wrong* one: no error, no wrong numbers, just
    whatever the pre-fix behavior already was, indefinitely.
21. **Three more Classic Admin date displays turned out to be unlocalized** (`next_geo_db_update`/
    `next_auto_prune` in `stats.html.twig`, `builtAt` in `widgets/geo-db-status.html.twig`) - same
    underlying bug class as #17/#18, found live-testing the fix for #20 above rather than #17's
    original round (these three carry a time-of-day, not just a calendar day, so they weren't caught
    by `longDay()`/`shortDay()`'s day-only scope at the time). See "Localized date formatting" in
    `ADMIN-UI.md` for the fix (`LocalizedDate::dateTime()`, `page_insights_localized_datetime`
    filter) and for the two structurally identical spots deliberately left alone (CLI/scheduler-log
    output, not an admin UI). A reminder that "found by live-testing, not by reading the code"
    (already the lesson of #18) keeps applying every time a *new* live-testing pass touches a screen
    nobody happened to look at during the previous one.
22. **Grav Maintainer review (issue #4237, `v3.0.1`) found three separate correctness/security
    issues in one pass.** (a) `getUserIP()` mutated `$_SERVER['REMOTE_ADDR']` in place from
    client-spoofable headers (`CF-Connecting-IP`/`Client-IP`/`X-Forwarded-For`) - the mutation
    persisted for the rest of the request, affecting anything downstream that reads `REMOTE_ADDR`
    directly, not just this plugin's own use of it. Fixed with a new `trust_proxy_headers` config
    option (default off) and a local variable instead of mutating the superglobal - see "Trusting
    proxy headers" precedent in the wiki's Privacy & Security page. (b) `_esc()` in
    `page-insights.js` didn't escape `"`/`'`, allowing stored XSS via the `route` column when
    interpolated into an HTML attribute. (c) A second, independent XSS vector was found while
    live-testing fix (b): `encodeURIComponent()` treats `'` as an "unreserved" character per the
    JS/ECMA spec (unlike RFC 3986), so URL-encoded values embedded in `href` attributes
    (`_pageCellHtml()`, `_userCellHtml()`) still carried a raw `'` even after URI-encoding - fixed
    by routing the URI-encoded value through `_esc()` too (double-encoding: URI + HTML-attribute).
    A reminder that a single external review pass can surface multiple, unrelated classes of bug in
    the same small area of code, and that fixing the first one found is a good moment to
    specifically look for a second.
23. **`blueprints.yaml`'s `version:` field wasn't bumped when tagging a release (`v3.1.0`).** GPM
    relies solely on that version string to decide whether to offer an update to an installation -
    not on any separate check of the actual installed code state. One test environment offered the
    update, another (with a stale blueprint version left over from before the fix) didn't, even
    though both were running the exact same released code underneath. A reminder to treat
    `blueprints.yaml`'s `version:` as part of the release checklist, not an afterthought that
    happens to usually track the tag.
24. **`datetime_offset`'s blueprint validation pattern used a POSIX character class
    (`[[:digit:]]`), valid PCRE for PHP-side validation but not valid JavaScript `RegExp` syntax** -
    Admin2 builds a native `RegExp` from that same pattern string client-side to validate the field
    live, so the POSIX class silently failed to compile there (while working fine server-side).
    Fixed by using `\d` instead, which is valid in both engines identically. Worth checking any new
    `validate.pattern` field against both regex engines, not just PHP's, given Admin2 reuses these
    patterns client-side - see the existing Admin1/Admin2 blueprint-incompatibility notes in
    `ARCHITECTURE.md`.
25. **The CI lint workflow silently passed (green) after a force-push, having skipped every syntax
    check.** `git diff --name-only "$BASE" HEAD > changed.txt || true` was meant to tolerate a
    diff that legitimately has zero changed files, but the `|| true` also swallowed the case where
    `git diff` itself *failed* - which happens whenever `github.event.before` points at a commit no
    longer reachable in the checked-out history, exactly what a force-push produces. The result was
    an empty `changed.txt`, which the rest of the workflow correctly (but misleadingly) interpreted
    as "nothing to check," reporting success without ever running `php -l`/`node --check`/the Twig
    check on anything. Fixed by verifying `$BASE` is actually reachable first (`git cat-file -e`)
    and falling back to a root-commit comparison instead of swallowing the error. A reminder that
    `|| true` on a command whose *failure* and *empty-but-successful result* both need to be
    tolerated is exactly the kind of thing that quietly defeats the point of the check it's
    protecting.
26. **A phantom "page" (`/page-stats/event-collection`, tens of thousands of hits) topped the Top
    Pages widget after the Page Stats -> Page Insights rename.** The frontend "time on page"
    collector ping (`js/ps.js`) was recognized server-side by an *exact* string comparison against
    the (now-renamed) `PATH_ADMIN_STATS` constant. Any hit still arriving on the old
    `/page-stats/event-collection` path - stale cached HTML that hadn't picked up the new collector
    URL yet - no longer matched, and fell through into `collectPageData()`, which deliberately also
    logs 404s (broken-link tracking, see `ignored_urls`) - so the dead old collector endpoint got
    tracked as a real, extremely popular page instead of being recognized and skipped. Present since
    the very first commit under the new name, not a regression from a later change. Fixed in
    `v3.1.5` by replacing the exact-path match with structural detection (`isCollectorRequest()`:
    POST + path ending in the fixed `PATH_EVENTS_COLLECTION` suffix), which is immune to any future
    rename or base-path/language-prefix difference. A reminder that recognizing an internal-only
    endpoint by exact-matching a *renamable* constant is fragile in a way that recognizing it
    structurally (method + fixed suffix) isn't - see the equivalent lesson already captured for
    this exact bug in the wiki's FAQ page.
27. **Plugin instance not reachable via `$grav['page-insights']`.** Grav never registers a plugin
    under its slug in the DI container - `$grav['page-insights']` (or any `??`-guarded variant of
    it) is always `null`, silently. The correct lookup is `Grav\Common\Plugins::getPlugin('page-insights')`,
    which iterates loaded plugins matching on `->name`.
28. **`$grav['pages']->routes()`/`getPagesCacheId()` silently empty on Admin2/API requests.**
    Admin2/API requests run with Grav's own `Pages::disablePages()` already set (a core
    performance shortcut for routes that don't need the full page tree) - this makes
    `Pages::init()` take a short-circuit branch that never calls `buildPages()`, so any code
    reading the full page tree gets an empty result with no exception. Any plugin code that needs
    the full page tree from an Admin2/API context must call `Pages::enablePages()` first. This bug
    and #27 above produced the identical symptom (empty result, HTTP 200, no error) - worth
    remembering as a diagnostic pattern specific to this codebase: an unexpectedly empty result
    from an Admin2/API-context request is as likely to be one of these two gotchas as an actual
    logic bug.
29. **`Cannot redeclare class ComposerAutoloaderInit...`** when running this plugin alongside
    another, related plugin (a forked sibling) in the same PHP process. Composer's
    `config.autoloader-suffix` is inherited from an existing `vendor/autoload.php` rather than
    regenerated by default - Page Insights' `vendor/` was copied 1:1 from Page Stats at fork time,
    including its suffix, so both plugins' compiled autoloaders declared the same class name.
    Fixed with an explicit, unique `config.autoloader-suffix` in `composer.json`.
30. **`migrate()` failed with `duplicate column name: browser` on a database copied/migrated from
    an existing Page Stats installation** (Codeberg issue #6). That source database's own schema
    already had `browser`/`browser_version`/`platform` (the columns migration 2 adds), but never
    ran this plugin's own `migrate()` before - no `migrations` table at all, so `migrate()`'s
    version lookup fell back to `0` ("nothing applied yet") and re-ran migration 2's
    `ALTER TABLE data ADD COLUMN browser ...` against a table that already had that column.
    Same underlying class of bug as #20 above (a database whose actual schema is ahead of what
    `migrations` records), but #20's fix (`hasPendingMigrations()`) only made *detecting* pending
    migrations reliable - it does nothing once `migrate()` actually starts re-running a migration
    file whose `ADD COLUMN` statements no longer apply cleanly. Unlike `CREATE TABLE`, SQLite has
    no `ADD COLUMN IF NOT EXISTS`, and since a migration file's statements all run through one
    `PDO::exec()` call, the duplicate-column error aborted every statement after it in that same
    file too - including `browser_version`/`platform`'s own `ADD COLUMN` and the file's closing
    `COMMIT TRANSACTION;`. Fixed with a new `Stats::skipExistingColumns()`, which strips any
    `ALTER TABLE ... ADD COLUMN ...` statement from a migration file's SQL whose column is already
    present (checked via `PRAGMA table_info`) before that file is executed - deliberately generic
    rather than special-cased to `browser`, so the same failure can't resurface for
    `browser_version`/`platform`/`referer`/`environment` or any future added column. See
    "Migrations" in `DATABASES.md`. **Reopened days later on the same report - see bug #31.**
31. **Same Codeberg issue #6, reopened: after the #30 fix, `migrate()` on that same reporter's
    database got further and then failed with `table events already exists`** (migration 4). The
    #30 fix only made `ALTER TABLE ... ADD COLUMN` idempotent - migration 4's
    `CREATE TABLE events (...)` had no `IF NOT EXISTS` at all (the one migration statement in the
    whole numbered sequence missing it; every other `CREATE TABLE`/`CREATE INDEX` shipped already
    had one), so it failed the same way ADD COLUMN previously did, for the same underlying reason:
    that reporter's database's schema is evidently far closer to this plugin's *final* target
    schema than its `migrations` table records, columns and whole tables both. Reported together
    with an explicit ask to audit every migration statement rather than patch each one as it's
    individually hit - a full statement-by-statement audit of all nine migration files (done as
    part of this fix) confirmed migration 4's `CREATE TABLE events` was in fact the *only* other
    non-idempotent statement across the whole sequence. Fixed two ways: migration 4.sql itself now
    reads `CREATE TABLE IF NOT EXISTS events` (matching every other `CREATE TABLE` in the codebase);
    and `skipExistingColumns()` was generalized into `skipAlreadyAppliedSchema()`, adding
    `skipExistingTables()`/`skipExistingIndexes()` as a safety net against a *future* migration
    statement shipping without `IF NOT EXISTS` (both are no-ops against every currently-shipped
    file, by design). Caught during that generalization, before it ever shipped: an initial version
    of `skipExistingTables()` checked table existence purely against live DB state, which mishandled
    migration 9's `DROP TABLE IF EXISTS rollup_daily; CREATE TABLE rollup_daily (...)`
    idempotent-rebuild pattern for the five rollup tables - by the time migration 9 runs those
    tables already exist (created in migrations 7/8), so that first version stripped the `CREATE
    TABLE` as "already exists" while leaving the `DROP TABLE IF EXISTS` in place, silently dropping
    every rollup table with no recreation. The fix (finding this before shipping, not from a report)
    was to make `skipExistingTables()` track any `DROP TABLE IF EXISTS <table>;` earlier in the same
    migration file's SQL and always keep that table's `CREATE TABLE` regardless of live DB state -
    see "Migrations" in `DATABASES.md`. A reminder that a generic idempotency shim operating on raw
    SQL text needs to understand statement *order and intra-file dependencies*, not just "does this
    object exist right now" - the same class of blind spot as bug #19's boundary-day rollup
    overcount (a fix correct in isolation, wrong once the surrounding sequence is taken into
    account), caught here by a synthetic-database regression test exercising the full 9-migration
    replay rather than each migration in isolation.

## Known cleanup items

None outstanding - the two leftover files from the Page Stats rename
(`classes/Api/PageStatsApiController.php`, `admin-next/pages/page-stats.js`) that used to be
listed here were removed; see the "Data cleanup"/renaming history in `CONTRIBUTING.md`'s "Project
history" section. If you find another orphaned leftover, add it here rather than just deleting it
silently, so the removal itself is discoverable.

---

## Auf Deutsch (Kurzfassung)

Diese Datei sammelt nicht-offensichtliche, bereits behobene Bugs aus der Geschichte des Plugins
samt Ursache und Fix-Begründung - nützlicher Kontext, bevor verwandter Code angefasst wird, da sich
einige davon leicht versehentlich wieder einschleichen lassen. Architektur, Schema und
Design-Ziele bleiben in `ARCHITECTURE.md`/`DATABASES.md`, dorthin wird verlinkt statt dupliziert.

Die Liste ist grob chronologisch (Einträge 1-21); spätere Ergänzungen (22+) wurden bei einer
Dokumentations-Konsolidierung angehängt statt eingefügt, damit bestehende Querverweise
("siehe Bug #12") gültig bleiben.

Wiederkehrende Muster, die mehrfach auftraten: Admin2-i18n-Übersetzungen wirken oft erst nach
`bin/grav clear-cache`, nicht nach einem reinen PHP-FPM-Neustart (Bugs #10/#11); Datumsformatierung
via Twig/PHP `date()` ist nie lokalisierungsfähig, egal wie unauffällig die Stelle wirkt (Bugs
#17/#18/#21); ein per Live-Test auf einer echten Admin-Instanz gefundener Fehler deckt oft weitere,
strukturell identische Stellen auf, die reine Code-Reviews übersehen (Bugs #18/#20/#21); ein
Performance-Fix kann eine bestehende, korrekte Query-Semantik stillschweigend brechen (Bug #15
und Bug #19 - Letzterer besonders tückisch, da die falschen Zahlen plausibel aussahen); und ein
Migrations-Mechanismus, der für einen Deployment-Weg (GPM/ZIP) getestet wurde, kann unter einem
anderen, gleichermaßen normalen (`git pull`) still versagen (Bug #20).
