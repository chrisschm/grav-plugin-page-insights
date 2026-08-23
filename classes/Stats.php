<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights;

use DateTimeImmutable;
use Grav\Common\Browser;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\PageInsights\Geolocation\GeolocationData;
use \PDO;

class Stats
{
    private $db;
    private $dbPath;
    private $config;
    private $botRegExp = '';
    private $environment;

    const FORCE_MIGRATION_FLAG = '/../data/migrations/MUST_MIGRATE';

    /**
     * $environment is Grav's own config('environment') value (see
     * Grav\Common\Config\Setup - defaults to the current request's
     * hostname, 'cli' outside a web request), passed in by the caller
     * rather than resolved here so this class never has to know how to
     * reach Grav's DI container. Used to scope every read to the current
     * site in a Grav multisite install (see query()'s "Multisite
     * (environment) scoping" and docs/DATABASES.md) - null (the CLI/
     * scheduler call sites' choice, see page-insights.php) disables that
     * scoping entirely, since a maintenance job (prune, vacuum,
     * rollup:build) always operates across every site's data at once, not
     * "the current site".
     */
    public function __construct($dbPath, $config, ?string $environment = null)
    {
        $this->config = $config;
        $this->environment = $environment;
        $this->botRegExp = implode('|', $this->config['bot_regexp']);
        $this->dt_offset = $this->config['datetime_offset'];

        $this->dbPath = new \SplFileInfo($dbPath);
        $freshOrForced = !$this->dbPath->isWritable() || file_exists(__DIR__ . self::FORCE_MIGRATION_FLAG);
        $this->db  = new PDO(
            'sqlite:' . $dbPath,
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Without these, concurrent requests writing to the same SQLite file
        // fail fast with "database is locked" (default busy_timeout is 0)
        // and every commit fsyncs the whole rollback journal by default.
        // Under real traffic (many simultaneous page views) that can pile up
        // PHP-FPM workers waiting on the same lock and, in the worst case,
        // exhaust the whole pool - taking down unrelated requests too.
        // WAL allows one writer + many concurrent readers instead of
        // serializing everything on a single file lock.
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');


        // $freshOrForced alone used to be the only trigger for migrate() on
        // an existing (already-writable) database - via
        // FORCE_MIGRATION_FLAG, a file shipped tracked in git and deleted by
        // migrate() itself once it succeeds (see that method's docblock).
        // That works for any deployment method that replaces the whole
        // plugin directory wholesale on update (a fresh GPM download, a
        // tarball extraction) - the flag's tracked content reappears
        // because the *entire* directory tree is overwritten. It silently
        // does not work for a `git pull`-based deployment: a `pull` only
        // applies the diff between the old and new commit for each path,
        // and FORCE_MIGRATION_FLAG's tracked content never actually changes
        // between releases, so a local deletion of it - which is exactly
        // what happens the very first time migrate() ever runs - has
        // nothing to "pull back", and it stays deleted forever. Every
        // migration shipped after the first one ever applied on such an
        // installation was then silently never running again - confirmed
        // in practice (see docs/HISTORY.md): two
        // real installations updated exclusively via `git pull` had been
        // stuck on an old schema version for weeks, missing not just this
        // release's rollup tables but also a previous release's
        // performance-critical indexes, with no error of any kind - a
        // missing index degrades a query silently, it doesn't throw.
        //
        // hasPendingMigrations() below closes that gap by checking, on
        // every request, whether data/migrations/ actually contains a
        // numbered file beyond what the "migrations" table has recorded -
        // independent of whether FORCE_MIGRATION_FLAG happens to exist on
        // disk. This makes migrate() self-triggering regardless of
        // deployment method. The extra cost in the (overwhelmingly common)
        // already-up-to-date steady state is one indexed `SELECT ... LIMIT
        // 1` plus one filesystem stat() per request - negligible next to
        // everything else this class already does per page view, and
        // nowhere near the scale of cost this session's benchmarking work
        // was actually concerned with (GROUP BY/DISTINCT scans over
        // millions of "data" rows, not a handful of migration files).
        $migrate = $freshOrForced || $this->hasPendingMigrations();
        if ($migrate) {
            $this->migrate();
        }

        // Every shipped migration file (data/migrations/*.sql) ends with an
        // explicit "PRAGMA foreign_keys = on;" - harmless for its original
        // purpose (a standalone script run once via a SQLite GUI/CLI
        // tool), but migrate() above runs that same SQL directly on
        // *this* connection, so on a freshly-migrated database the
        // pragma silently stays on for the rest of this connection's
        // lifetime - contradicting the rest of this class, which
        // documents and relies on foreign keys never being enforced
        // (see collectEvent()'s docblock, and pruneData()/
        // pruneOrphanedEvents(), which delete "data" rows that older
        // "events" rows may still point at, by design, on the
        // now-unenforced REFERENCES). Only actually observed with an
        // empty freshly-installed database (migrate() only ever runs
        // once per install, before FORCE_MIGRATION_FLAG is deleted at
        // its end) - reset explicitly here rather than relying on that
        // timing, so this connection's behaviour never depends on
        // whether migrate() just ran.
        $this->db->exec('PRAGMA foreign_keys = OFF');
    }

    private function getUserAgent()
    {
        if (array_key_exists('HTTP_USER_AGENT', $_SERVER)) {
            return $_SERVER['HTTP_USER_AGENT'];
        }

        return '';
    }

    /**
     * Whether data/migrations/ contains a numbered *.sql file beyond what
     * the "migrations" table currently has recorded as applied - i.e.
     * whether migrate() actually has work to do right now, independent of
     * FORCE_MIGRATION_FLAG (see the long comment in __construct() for why
     * that flag alone isn't a reliable trigger under a `git pull`-based
     * deployment). Deliberately mirrors migrate()'s own version-lookup
     * query and fallback ($version = 0, i.e. "definitely pending") rather
     * than sharing code with it - migrate() itself only needs to run this
     * lookup once per actual migration run, this needs to run it on every
     * request, so keeping them independent avoids coupling the cheap check
     * to whatever migrate() might grow in the future.
     */
    private function hasPendingMigrations(): bool
    {
        $version = 0;
        try {
            $q = $this->query('SELECT version FROM migrations ORDER BY id Desc LIMIT 1');
            if ($q) {
                $version = (int) $q[0]['version'];
            }
        } catch (\Throwable $e) {
            // No usable "migrations" table at all (e.g. mid-migration
            // build, or a database that predates it entirely) - treat as
            // "definitely pending", same as migrate()'s own fallback.
            return true;
        }

        $next = new \SplFileInfo(__DIR__ . '/../data/migrations/' . ($version + 1) . '.sql');
        return $next->isFile();
    }

    /**
     * executes a db migration by running the <int>.sql files not executted yet
     */
    public function migrate()
    {
        $version = 0;
        try {
            $q = 'SELECT version FROM migrations ORDER BY id Desc LIMIT 1';

            $q = $this->query($q);

            if ($q) {
                $version = $q[0]['version'];
            }
        } catch (\Throwable $e) {
            $version = 0;
        }

        error_log("==> page-insights:last-migration " . $version);

        while (true) {
            $version++;
            $file = new \SplFileInfo(__DIR__ . '/../data/migrations/' . $version . '.sql');
            error_log("==> page-insights:migrate " . $file->getBasename());
            if (!$file->isFile()) {
                break;
            }
            $contents = file_get_contents((string) $file);
            $this->db->exec($contents);
            $this->db->exec('INSERT INTO migrations (version) VALUES(' . $version . ');');
        }

        // Only ever guaranteed to exist when migrate() was entered via
        // $freshOrForced - hasPendingMigrations() (see __construct()) can
        // now also trigger a migrate() run with the flag never having
        // existed on disk at all (that's the whole point of it), so an
        // unconditional unlink() here would throw a PHP warning in exactly
        // the scenario this fix exists for.
        if (file_exists(__DIR__ . self::FORCE_MIGRATION_FLAG)) {
            unlink(__DIR__ . self::FORCE_MIGRATION_FLAG);
        }
    }

    /**
     * tries to detect if an user agent belongs to a bot
     */
    private function isBot()
    {

        return preg_match('/'. $this->botRegExp .'/i', $this->getUserAgent());
    }

    /**
     * Database statistics: file path/size, plus (see AutoSchedule) when the
     * two optional automatic maintenance jobs (geo-db update, data prune -
     * see PageInsightsPlugin::onSchedulerInitialized()) will next run, or
     * null for a job that's currently "disabled". Deliberately computed
     * here rather than in a dedicated method/endpoint: this one method
     * already backs both admin UIs' "database size" display (Classic
     * Admin's stats.html.twig titlebar via pageStats.db.dbStats, Admin2's
     * dashboard toolbar via PageInsightsApiController::overview()'s `db`
     * field) - piggy-backing on it means both get the schedule info for
     * free, with no new route/twig variable to keep in sync.
     */
    public function dbStats()
    {
        $nextGeoDbUpdate = AutoSchedule::nextRun(GRAV_ROOT, 'geo-db-update', (string) ($this->config['geo_db_auto_update'] ?? 'disabled'));
        $nextAutoPrune = AutoSchedule::nextRun(GRAV_ROOT, 'data-auto-prune', (string) ($this->config['data_auto_prune'] ?? 'disabled'));

        return [
            'mb' => round($this->dbPath->getSize() / 1024 / 1024, 1),
            'path' => (string) $this->dbPath,
            'next_geo_db_update' => $nextGeoDbUpdate?->getTimestamp(),
            'next_auto_prune' => $nextAutoPrune?->getTimestamp(),
        ];
    }

    /**
     * Deletes "data" rows (page hits) older than $before, plus - always, as
     * a side effect - any "events" rows left pointing at a now-deleted
     * "data" row (see pruneOrphanedEvents()). Used by both `bin/plugin
     * page-insights prune` (cli/PruneCommand.php) and the scheduled
     * equivalent (PageInsightsPlugin::registerAutoPruneJob()), so manual and
     * automatic pruning always behave identically.
     *
     * Uses the same datetime()-wrapped comparison as query()'s date range
     * filter, and for the same reason: a plain text "date < :cutoff"
     * comparison is a pure string comparison in SQLite and gives wrong
     * results across rows stored with differing UTC offsets (see the
     * docblock on query() - the historical "recently viewed pages" bug this
     * plugin already fixed once for reads applies identically here).
     *
     * @return int Number of deleted "data" rows.
     */
    public function pruneData(DateTimeImmutable $before): int
    {
        $s = $this->db->prepare('DELETE FROM data WHERE datetime(date) < datetime(:cutoff)');
        $s->bindValue(':cutoff', $before->format('c'));
        $s->execute();
        $deleted = $s->rowCount();

        $this->pruneOrphanedEvents();

        return $deleted;
    }

    /**
     * Deletes every "data" row recognized as bot traffic (is_bot = 1) -
     * deliberately no age cutoff at all, unlike pruneData() above. Bot/404
     * cleanup (this and pruneNotFoundHits() below) answers a different
     * question than age-based pruning: once a site's SEO work is done,
     * historical bot hits are typically no longer useful for anything,
     * regardless of how old or recent they are - see docs/MAINTENANCE.md,
     * "Admin2 database maintenance dialog", for the fuller reasoning and
     * why this is a manually-triggered action (CLI or dialog), never
     * automatic.
     *
     * Same "best-effort classification, not a guarantee" caveat as every
     * other use of this column - see docs/DATABASES.md, "Bot detection
     * reliability": a bot hit not recognized as such at collection time
     * (e.g. a scraper spoofing a real browser's User-Agent) is untouched by
     * this, and running it again after editing `bot_regexp` does not
     * retroactively catch anything newly recognized either - `is_bot` is
     * only ever set once, at insert time.
     *
     * @return int Number of deleted "data" rows.
     */
    public function pruneBotTraffic(): int
    {
        $deleted = (int) $this->db->exec('DELETE FROM data WHERE is_bot = 1');

        $this->pruneOrphanedEvents();

        return $deleted;
    }

    /**
     * Deletes every "data" row recorded as a 404 (http_code = 404) - same
     * no-age-bound rationale as pruneBotTraffic() above. "http_code" is
     * only populated since 2026-08-19 (see docs/DATABASES.md, table
     * "data") - rows written before that have a NULL http_code and are
     * untouched by this, the same rows statusCodeSummary() already bins
     * into its "other" bucket rather than guessing at their status.
     *
     * @return int Number of deleted "data" rows.
     */
    public function pruneNotFoundHits(): int
    {
        $deleted = (int) $this->db->exec('DELETE FROM data WHERE http_code = 404');

        $this->pruneOrphanedEvents();

        return $deleted;
    }

    /**
     * Deletes "events" rows whose "session_id" no longer matches any "data"
     * row - independent of any age cutoff. "events.session_id" is declared
     * as "REFERENCES data (id)" in the schema, but without an ON DELETE
     * CASCADE clause, and this class never runs "PRAGMA foreign_keys = ON"
     * on its own connection (see collectEvent()'s docblock) - so removing a
     * "data" row, whether via pruneData() or any other means, never
     * automatically takes its events with it. pruneData() already calls
     * this itself after every run; exposed standalone (see
     * cli/EventsPruneOrphansCommand.php) for cleaning up drift that
     * predates this method, without touching any otherwise-still-current
     * "data" rows.
     *
     * @return int Number of deleted "events" rows.
     */
    public function pruneOrphanedEvents(): int
    {
        return (int) $this->db->exec('DELETE FROM events WHERE session_id NOT IN (SELECT id FROM data)');
    }

    /**
     * Rebuilds the SQLite file to actually reclaim the disk space of
     * deleted rows - SQLite otherwise only frees the pages internally and
     * keeps the file itself at its largest-ever size. Never run implicitly
     * by pruneData()/pruneOrphanedEvents(): VACUUM needs a brief exclusive
     * lock on the whole database, so callers decide when that's acceptable
     * (see cli/VacuumCommand.php and the --vacuum option on `prune`).
     *
     * @return array{before: int, after: int} File size in bytes, before/after.
     */
    public function vacuum(): array
    {
        $path = (string) $this->dbPath;

        // In WAL mode (see __construct()) VACUUM's rewritten pages land in
        // the WAL file first, same as any other write - the main file's
        // on-disk size doesn't reflect the shrink until those pages are
        // checkpointed back into it. Without an explicit TRUNCATE
        // checkpoint, filesize() below would report the same, unchanged
        // size before and after VACUUM even though it worked - verified
        // against a scratch database. before/after are checkpointed
        // identically so the comparison is meaningful either way.
        $this->db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        clearstatcache(true, $path);
        $before = @filesize($path) ?: 0;

        $this->db->exec('VACUUM');
        $this->db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        clearstatcache(true, $path);
        $after = @filesize($path) ?: 0;

        return ['before' => $before, 'after' => $after];
    }

    // Bounds for the unauthenticated /event-collection endpoint. It has no
    // auth, no nonce and no rate limiter in front of it (it's a frontend
    // route, so the API plugin's own limiter never sees it) - without
    // these, anyone could insert rows indefinitely until the disk fills.
    private const MAX_EVENT_STRING_LENGTH = 255; // matches the "events" table's VARCHAR(255) columns
    private const MAX_EVENTS_PER_SESSION = 2000; // headroom for several hours of a legitimately open tab

    /**
     * collects stats about a page event
     */
    public function collectEvent(string $sid, string $name, string $value): void
    {
        // "events.session_id" is declared as "REFERENCES data (id)" in the
        // schema, but SQLite only enforces foreign keys on a connection
        // that has run "PRAGMA foreign_keys = ON" - which this class never
        // does for its runtime connection (see __construct()). The
        // reference is therefore documentation only; without this explicit
        // check any caller-supplied session_id would be accepted as-is,
        // whether or not it corresponds to a real page hit.
        if (!ctype_digit($sid)) {
            return;
        }
        $exists = $this->db->prepare('SELECT 1 FROM data WHERE id = :id LIMIT 1');
        $exists->bindValue(':id', $sid, PDO::PARAM_INT);
        $exists->execute();
        if (false === $exists->fetchColumn()) {
            return;
        }

        $count = $this->db->prepare('SELECT COUNT(*) FROM events WHERE session_id = :id');
        $count->bindValue(':id', $sid, PDO::PARAM_INT);
        $count->execute();
        if ((int) $count->fetchColumn() >= self::MAX_EVENTS_PER_SESSION) {
            return;
        }

        $name = mb_substr($name, 0, self::MAX_EVENT_STRING_LENGTH);
        $value = mb_substr($value, 0, self::MAX_EVENT_STRING_LENGTH);

        $s = $this->db->prepare('
            INSERT INTO events
                ("session_id", "event", "value")
            VALUES
                (:session_id, :event, :value)
        ');

        $s->bindValue(':session_id', $sid, PDO::PARAM_INT);
        $s->bindValue(':event', $name);
        $s->bindValue(':value', $value);

        $s->execute();
    }

    /**
     * save stats into db.
     * It returns the last inserted id on the table, this can be used and a FK for logging events for that session
     *
     * @returns string "0" on error or the last insert id otherwise
     */
    public function collect(string $ip, GeolocationData $geo, PageInterface $page, $uri,  UserInterface $user, DateTimeImmutable $date, Browser $browser): string
    {
        if ($this->isBot()) {
            if (false === $this->config['log_bot']) {
                error_log('Bot detected and we are configured to not log bot activiy');
                return "0";
            }
        }

        if ($this->config['log_admin'] == false &&  $user->authorize('admin.login')) {
            error_log('=====>> Admin user detected, we are configured not to log admin activity.');
            return "0";
        }

        $s = $this->db->prepare('
            INSERT INTO data
                ("ip", "country", "city", "region", "route", "page_title", "user", "date", "user_agent", "is_bot", "browser", "browser_version", "platform", "referer", "http_code", "environment")
             VALUES
                (:ip, :country, :city, :region, :route, :title, :user, :date, :user_agent, :is_bot, :browser, :browser_version, :platform, :referer, :http_code, :environment)
        ');


        $isNotFound = ('notfound' == $page->template());
        if ($isNotFound) {
            $pageTitle = (string) $uri;
        }
        $s->bindValue(':ip', $ip);
        $s->bindValue(':country', $geo->countryCode());
        $s->bindValue(':city', $geo->city());
        $s->bindValue(':region', $geo->region());
        $s->bindValue(':route', (string) $uri);
        $s->bindValue(':title', $pageTitle ?? $page->title());
        $s->bindValue(':user', $user->username);
        $s->bindValue(':date', $date->format('c'));
        $s->bindValue(':user_agent', $this->getUserAgent());
        $s->bindValue(':is_bot', $this->isBot());
        $s->bindValue(':browser', $browser->getBrowser());
        $s->bindValue(':browser_version', $browser->getVersion());
        $s->bindValue(':platform', $browser->getPlatform());
        $s->bindValue(':referer', $_SERVER['HTTP_REFERER']??'');
        // Only 200/404 are ever written here - the only two states this
        // hook can determine reliably (see isNotFound above, the same
        // signal $pageTitle already relies on). Redirects/403/etc. aren't
        // attempted; statusCodeSummary() bins anything that isn't exactly
        // 200 or 404 - including NULL from rows written before this column
        // existed - into a forward-compatible "other" bucket, rather than
        // this method guessing at a code it can't actually verify.
        $s->bindValue(':http_code', $isNotFound ? 404 : 200, \PDO::PARAM_INT);
        $s->bindValue(':environment', $this->environment);

        $s->execute();

        return $this->db->lastInsertId();
    }

    /**
     * $scopeByEnvironment defaults to true (every current call site queries
     * "data", which has the column) - the one exception is timeOnPage()'s
     * "events" query, which passes false explicitly since "events" has no
     * "environment" column at all (see docs/DATABASES.md, "Multisite
     * (environment) scoping" for why that table doesn't need one).
     */
    private function query(string $q, array $params = [], ?int $limit = null, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, bool $scopeByEnvironment = true)
    {
        $where = [];
        $bindings = [];

        // Multisite (environment) scoping - see the docblock above and
        // docs/DATABASES.md. A NULL "environment" (rows collected before
        // this column existed) is deliberately treated as visible to
        // *every* site, not hidden from all of them - see migration 9's
        // own comment for the reasoning. $this->environment being null
        // (the CLI/scheduler call sites, see page-insights.php) disables
        // this filter entirely - those jobs operate across every site's
        // data at once, never "the current site".
        if ($scopeByEnvironment && $this->environment !== null) {
            $where[] = '(environment = :environment OR environment IS NULL)';
            $bindings['environment'] = $this->environment;
        }

        // Filters passed in by the caller. A scalar value (e.g.
        // ['route' => $route]) becomes an equality check; an array value
        // (e.g. ['route' => $realPageRoutes]) becomes an IN(...) check -
        // used by the "only real pages" scope filter on Recently viewed
        // pages, see PageInsightsPlugin::getRealPageRoutes(). A caller
        // passing an empty array (whitelist came back empty) gets a
        // deliberately unsatisfiable "1 = 0" rather than silently falling
        // back to "no filter" / returning everything.
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                if (empty($value)) {
                    $where[] = '1 = 0';
                    continue;
                }
                $placeholders = [];
                foreach (array_values($value) as $i => $v) {
                    $placeholder = "{$key}_{$i}";
                    $placeholders[] = ":$placeholder";
                    $bindings[$placeholder] = $v;
                }
                $where[] = "$key IN (" . implode(', ', $placeholders) . ')';
                continue;
            }

            $where[] = "$key = :$key";
            $bindings[$key] = $value;
        }

        // Date range, handled separately so it never gets run back through
        // the equality-filter loop above (that previously generated a bogus
        // "date_from = :date_from" clause - there is no such column, only
        // "date" - and used a mismatched :dateTo/:date_to placeholder).
        if ($dateFrom && $dateTo) {
            // Plain "date BETWEEN :date_from AND :date_to" is a pure text
            // comparison in SQLite - it does NOT account for differing UTC
            // offsets between the compared ISO-8601 strings. Rows are
            // inserted with PHP's local default timezone offset (e.g.
            // "+02:00" in summer), while the Admin2 dashboard always sends
            // its date_from/date_to bounds in UTC (JS Date#toISOString()).
            // A row timestamped "just now" in +02:00 then lexicographically
            // sorts AFTER a UTC "now" bound (its hour digits are numerically
            // higher for the same real instant), so it gets excluded from
            // BETWEEN as if it were in the future - the freshest hits are
            // the ones most likely to sit right at that boundary. datetime()
            // normalizes both sides (any valid offset) before comparing,
            // same trick already used for the day/time columns below.
            $where[] = ' datetime(date) BETWEEN datetime(:date_from) AND datetime(:date_to)';
            $bindings['date_from'] = $dateFrom->format('c');
            $bindings['date_to'] = $dateTo->format('c');
        }

        if (count($where)) {
            $q =  str_replace('%where', ' WHERE ' . implode(' AND ', $where), $q);
        } else {
            $q = str_replace('%where', '', $q);
        }

        if ($limit && (int) $limit > 0) {
            $q .= ' LIMIT :limit';
            $bindings['limit'] = $limit;
        }

        $s = $this->db->prepare($q);

        foreach ($bindings as $key => $value) {
            // Defensive: only bind values whose placeholder actually made it
            // into the final SQL. A couple of query builder methods in this
            // class accept $dateFrom/$dateTo (or other $params) but forget
            // to include '%where' in their SQL, which meant these bindings
            // had nothing to attach to and SQLite rejected them with
            // "column index out of range".
            if (str_contains($q, ':' . $key)) {
                $s->bindValue(':' . $key, $value);
            }
        }

        if (str_contains($q, ':offset')) {
            $s->bindValue(':offset', $this->dt_offset);
        }


        $s->execute();

        return $s->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * gets most viewed pages
     *
     * $params is an optional equality-filter array (same convention as
     * topBrowsers()/topCountries()/recentPages() etc.), e.g. ['user' => ...]
     * or ['ip' => ...] to get the pages a specific visitor viewed most,
     * for Admin2's User Detail view.
     */
    public function pagesSummary(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        // Fast path: query rollup_route (see docs/DATABASES.md, "Rollups")
        // instead of aggregating "data" live, when possible. Only safe when
        // $params is something rollup_route can actually answer on its own
        // - just 'is_bot' (hide_bots) and/or 'route' (the "real pages"
        // scope) - and when a date range was given at all (an unbounded
        // "all time" call has no day range to look up rollup coverage
        // against). Anything else - notably 'user'/'ip', used by the
        // Page/User Detail per-visitor drilldowns - asks a per-visitor
        // question the rollup was never built to answer, so those callers
        // transparently keep using the original live query below.
        if ($dateFrom && $dateTo && empty(array_diff(array_keys($params), ['is_bot', 'route']))) {
            return $this->pagesSummaryViaRollup($limit, $dateFrom, $dateTo, $params);
        }

        $q = 'SELECT route, page_title, count(route) as hits, count(distinct ip) as visitors, count(distinct user) as users FROM data %where GROUP BY page_title ORDER BY hits DESC';

        return $this->query($q, $params, $limit, $dateFrom, $dateTo);
    }

    /**
     * pagesSummary()'s rollup-backed fast path. Combines whatever the
     * rollup_route table already covers (SUM() over the pre-aggregated
     * per-day rows - cheap regardless of how much traffic those days saw)
     * with a live query against "data" for whatever it doesn't cover yet -
     * normally just the still-accumulating current day, but transparently
     * the *entire* requested range if rollupDay() has never run at all
     * (rollupStatus() === null), which is what makes this degrade safely
     * to today's original behaviour on a fresh/not-yet-rolled-up install.
     *
     * Summing "visitors"/"users" across more than one day is a deliberate,
     * documented approximation (see the comment on rollup_daily in
     * data/migrations/7.sql) - it can overcount a visitor who came back on
     * more than one of the summed days. "hits" has no such issue: a
     * straight count is always exact regardless of how many days it's
     * summed over.
     *
     * The first and last calendar day touched by [$dateFrom, $dateTo] are
     * deliberately *never* served from the rollup, even when already
     * covered by rollupStatus() - only the days strictly between them are.
     * rollup_route has no granularity finer than a whole day, but $dateFrom
     * (e.g. Admin2's "last 30 days") is an exact instant that only rarely
     * lands exactly on a day boundary; summing the *whole* rollup day it
     * falls in - as an earlier version of this method did - silently
     * pulled in hours from before $dateFrom too (caught by comparing
     * against the original live query on a synthetic benchmark DB, see
     * docs/DATABASES.md - a several-percent overcount on every widget,
     * not an edge case). Always routing both boundary days through the
     * live query below costs at most two days out of the range, however
     * long it is - negligible next to the win from the (usually many more)
     * interior days.
     */
    private function pagesSummaryViaRollup(int $limit, DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo, array $params)
    {
        $coverage = $this->rollupInteriorCoverage($dateFrom, $dateTo);

        $rows = [];
        $liveQuery = 'SELECT route, page_title, count(route) as hits, count(distinct ip) as visitors, count(distinct user) as users FROM data %where GROUP BY page_title';

        if ($coverage !== null) {
            [$interiorFromDay, $coveredToDay] = $coverage;
            $rows = $this->pagesSummaryRollupPart($interiorFromDay, $coveredToDay, $params);

            $coveredStart = new DateTimeImmutable($interiorFromDay);
            $coveredEndExclusive = (new DateTimeImmutable($coveredToDay))->modify('+1 day');

            if ($dateFrom < $coveredStart) {
                $rows = array_merge($rows, $this->query($liveQuery, $params, null, $dateFrom, $coveredStart->modify('-1 second')));
            }
            if ($coveredEndExclusive <= $dateTo) {
                $rows = array_merge($rows, $this->query($liveQuery, $params, null, $coveredEndExclusive, $dateTo));
            }
        } else {
            // Nothing usable from the rollup (range spans less than 3 days,
            // or isn't rolled up far enough yet) - entire range live,
            // exactly like pagesSummary() before this method existed.
            $rows = $this->query($liveQuery, $params, null, $dateFrom, $dateTo);
        }

        $merged = [];
        foreach ($rows as $r) {
            $key = $r['page_title'];
            if (!isset($merged[$key])) {
                $merged[$key] = $r;
                $merged[$key]['hits'] = (int) $r['hits'];
                $merged[$key]['visitors'] = (int) $r['visitors'];
                $merged[$key]['users'] = (int) $r['users'];
                continue;
            }
            $merged[$key]['hits'] += (int) $r['hits'];
            $merged[$key]['visitors'] += (int) $r['visitors'];
            $merged[$key]['users'] += (int) $r['users'];
            if (!empty($r['route'])) {
                $merged[$key]['route'] = $r['route']; // prefer the live/most-recent route, same "arbitrary but stable" spirit as the original GROUP BY page_title
            }
        }

        usort($merged, static fn($a, $b) => $b['hits'] <=> $a['hits']);

        return array_slice(array_values($merged), 0, $limit);
    }

    private function pagesSummaryRollupPart(string $fromDay, string $toDay, array $params): array
    {
        $where = ['day BETWEEN :from_day AND :to_day'];
        $bindings = [':from_day' => $fromDay, ':to_day' => $toDay];

        if (array_key_exists('is_bot', $params)) {
            $where[] = 'is_bot = :is_bot';
            $bindings[':is_bot'] = $params['is_bot'];
        }

        $this->appendEnvironmentFilter($where, $bindings);

        if (array_key_exists('route', $params)) {
            $routes = $params['route'];
            if (empty($routes)) {
                return [];
            }
            $placeholders = [];
            foreach (array_values($routes) as $i => $v) {
                $ph = ":route_$i";
                $placeholders[] = $ph;
                $bindings[$ph] = $v;
            }
            $where[] = 'route IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = '
            SELECT page_title, MIN(route) as route, SUM(hits) as hits, SUM(visitors) as visitors, SUM(users) as users
            FROM rollup_route
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY page_title
        ';

        $s = $this->db->prepare($sql);
        $s->execute($bindings);

        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * (Re)computes rollup_daily + rollup_route for exactly one calendar
     * day, and advances rollup_state accordingly. Idempotent - deletes
     * that day's existing rollup rows first, so re-running it (e.g. to
     * backfill history, or to correct a day after a bug fix) is always
     * safe and never double-counts.
     *
     * The day boundary matches the same "date(datetime(date), :offset)"
     * calendar-day bucketing already used elsewhere (recentPages(),
     * siteSummary()) - not a plain UTC calendar day - so a rolled-up day
     * groups exactly the same rows a live query would have grouped into
     * that day. The WHERE clause first narrows via the existing
     * idx_data_date_normalized expression index with a generous +/-1 day
     * pad (covers any real-world UTC offset) before applying the exact
     * per-offset day equality, so this still benefits from an index seek
     * despite scanning the whole table's worth of days over time.
     *
     * @return array{day: string, daily_rows: int, route_rows: int}
     */
    public function rollupDay(DateTimeImmutable $day): array
    {
        $dayStr = $day->format('Y-m-d');
        $roughFrom = $day->modify('-1 day')->format('c');
        $roughTo = $day->modify('+2 days')->format('c');

        $this->db->beginTransaction();
        try {
            $del1 = $this->db->prepare('DELETE FROM rollup_daily WHERE day = :day');
            $del1->execute([':day' => $dayStr]);

            $del2 = $this->db->prepare('DELETE FROM rollup_route WHERE day = :day');
            $del2->execute([':day' => $dayStr]);

            $del3 = $this->db->prepare('DELETE FROM rollup_country WHERE day = :day');
            $del3->execute([':day' => $dayStr]);

            $del4 = $this->db->prepare('DELETE FROM rollup_browser WHERE day = :day');
            $del4->execute([':day' => $dayStr]);

            $del5 = $this->db->prepare('DELETE FROM rollup_platform WHERE day = :day');
            $del5->execute([':day' => $dayStr]);

            $dailyStmt = $this->db->prepare('
                INSERT INTO rollup_daily (day, is_bot, environment, hits, visitors, users, http_200, http_404, http_other)
                SELECT
                    :day AS day,
                    is_bot,
                    environment,
                    COUNT(*) AS hits,
                    COUNT(DISTINCT ip) AS visitors,
                    COUNT(DISTINCT user) AS users,
                    SUM(CASE WHEN http_code = 200 THEN 1 ELSE 0 END) AS http_200,
                    SUM(CASE WHEN http_code = 404 THEN 1 ELSE 0 END) AS http_404,
                    SUM(CASE WHEN http_code IS NULL OR http_code NOT IN (200, 404) THEN 1 ELSE 0 END) AS http_other
                FROM data
                WHERE datetime(date) BETWEEN datetime(:rough_from) AND datetime(:rough_to)
                  AND date(datetime(date), :offset) = :day2
                GROUP BY is_bot, environment
            ');
            $dailyStmt->execute([
                ':day' => $dayStr, ':rough_from' => $roughFrom, ':rough_to' => $roughTo,
                ':offset' => $this->dt_offset, ':day2' => $dayStr,
            ]);
            $dailyRows = $dailyStmt->rowCount();

            $routeStmt = $this->db->prepare('
                INSERT INTO rollup_route (day, is_bot, environment, page_title, route, hits, visitors, users)
                SELECT
                    :day AS day,
                    is_bot,
                    environment,
                    page_title,
                    MIN(route) AS route,
                    COUNT(*) AS hits,
                    COUNT(DISTINCT ip) AS visitors,
                    COUNT(DISTINCT user) AS users
                FROM data
                WHERE datetime(date) BETWEEN datetime(:rough_from) AND datetime(:rough_to)
                  AND date(datetime(date), :offset) = :day2
                GROUP BY is_bot, environment, page_title
            ');
            $routeStmt->execute([
                ':day' => $dayStr, ':rough_from' => $roughFrom, ':rough_to' => $roughTo,
                ':offset' => $this->dt_offset, ':day2' => $dayStr,
            ]);
            $routeRows = $routeStmt->rowCount();

            // Migration 8 (see its own comment for why these are three
            // separate narrow tables rather than one, and why "hits" only -
            // no visitors/users, unlike rollup_route above).
            $countryStmt = $this->db->prepare('
                INSERT INTO rollup_country (day, is_bot, environment, country, hits)
                SELECT
                    :day AS day,
                    is_bot,
                    environment,
                    country,
                    COUNT(*) AS hits
                FROM data
                WHERE datetime(date) BETWEEN datetime(:rough_from) AND datetime(:rough_to)
                  AND date(datetime(date), :offset) = :day2
                GROUP BY is_bot, environment, country
            ');
            $countryStmt->execute([
                ':day' => $dayStr, ':rough_from' => $roughFrom, ':rough_to' => $roughTo,
                ':offset' => $this->dt_offset, ':day2' => $dayStr,
            ]);
            $countryRows = $countryStmt->rowCount();

            $browserStmt = $this->db->prepare('
                INSERT INTO rollup_browser (day, is_bot, environment, browser, hits)
                SELECT
                    :day AS day,
                    is_bot,
                    environment,
                    browser,
                    COUNT(*) AS hits
                FROM data
                WHERE datetime(date) BETWEEN datetime(:rough_from) AND datetime(:rough_to)
                  AND date(datetime(date), :offset) = :day2
                GROUP BY is_bot, environment, browser
            ');
            $browserStmt->execute([
                ':day' => $dayStr, ':rough_from' => $roughFrom, ':rough_to' => $roughTo,
                ':offset' => $this->dt_offset, ':day2' => $dayStr,
            ]);
            $browserRows = $browserStmt->rowCount();

            $platformStmt = $this->db->prepare('
                INSERT INTO rollup_platform (day, is_bot, environment, platform, hits)
                SELECT
                    :day AS day,
                    is_bot,
                    environment,
                    platform,
                    COUNT(*) AS hits
                FROM data
                WHERE datetime(date) BETWEEN datetime(:rough_from) AND datetime(:rough_to)
                  AND date(datetime(date), :offset) = :day2
                GROUP BY is_bot, environment, platform
            ');
            $platformStmt->execute([
                ':day' => $dayStr, ':rough_from' => $roughFrom, ':rough_to' => $roughTo,
                ':offset' => $this->dt_offset, ':day2' => $dayStr,
            ]);
            $platformRows = $platformStmt->rowCount();

            $upsert = $this->db->prepare("
                INSERT INTO rollup_state (job, last_rolled_up_day) VALUES ('daily', :day)
                ON CONFLICT(job) DO UPDATE SET last_rolled_up_day = excluded.last_rolled_up_day
                    WHERE rollup_state.last_rolled_up_day IS NULL OR excluded.last_rolled_up_day > rollup_state.last_rolled_up_day
            ");
            $upsert->execute([':day' => $dayStr]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'day' => $dayStr,
            'daily_rows' => $dailyRows,
            'route_rows' => $routeRows,
            'country_rows' => $countryRows,
            'browser_rows' => $browserRows,
            'platform_rows' => $platformRows,
        ];
    }

    /**
     * The most recent calendar day rollupDay() has (re)computed, or null
     * if it's never run on this database yet - e.g. right after upgrading
     * to this version, before either the scheduled job or
     * `rollup:build` has run for the first time.
     */
    public function rollupStatus(): ?string
    {
        $row = $this->query("SELECT last_rolled_up_day FROM rollup_state WHERE job = 'daily' LIMIT 1");

        return $row[0]['last_rolled_up_day'] ?? null;
    }

    /**
     * Shared boundary-day-safe rollup coverage window for a
     * [$dateFrom, $dateTo] range, used by every *ViaRollup() method below.
     * Originally written inline inside pagesSummaryViaRollup() (see that
     * method's docblock for the full "why boundary days are never rolled
     * up" rationale - docs/HISTORY.md #19);
     * extracted here once the same logic needed repeating for
     * topCountries()/topBrowsers()/topPlatforms()/statusCodeSummary()/
     * totalUniqueVisitors()/totalUniqueUsers()/siteSummary() (migration 8)
     * rather than risk a fresh copy of that boundary math getting it wrong
     * again.
     *
     * Returns [$interiorFromDay, $coveredToDay] (both 'Y-m-d' strings) if
     * the rollup tables have *any* usable interior coverage for this range,
     * or null if not (the range spans fewer than 3 calendar days, or
     * rollupDay() hasn't run far enough yet) - callers fall back to an
     * entirely live query in that case, exactly like before any rollup
     * existed. $interiorFromDay/(the caller's own $interiorToDay, not
     * returned - callers already have $dateTo to derive it, or use
     * $coveredToDay directly) deliberately exclude the first/last calendar
     * day touched by $dateFrom/$dateTo - only strictly-interior days are
     * ever safe to serve from a whole-day rollup row.
     */
    private function rollupInteriorCoverage(DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo): ?array
    {
        $rolledUpTo = $this->rollupStatus();

        $interiorFromDay = $dateFrom->modify('+1 day')->format('Y-m-d');
        $interiorToDay = $dateTo->modify('-1 day')->format('Y-m-d');

        if ($rolledUpTo === null || $interiorFromDay > $interiorToDay || $rolledUpTo < $interiorFromDay) {
            return null;
        }

        return [$interiorFromDay, min($rolledUpTo, $interiorToDay)];
    }

    /**
     * Shared multisite (environment) scoping for every *RollupPart()/
     * *ViaRollup() raw-SQL query below - same rule and same "NULL is
     * visible everywhere" reasoning as query()'s own environment
     * condition (see that method's docblock and docs/DATABASES.md), just
     * appended by hand to a $where/$bindings pair instead of going
     * through query()'s generic %where mechanism, since these queries
     * target the rollup_* tables directly rather than "data". Extracted
     * once it needed repeating identically across five methods, same
     * reasoning as rollupInteriorCoverage() above.
     */
    private function appendEnvironmentFilter(array &$where, array &$bindings): void
    {
        if ($this->environment !== null) {
            $where[] = '(environment = :environment OR environment IS NULL)';
            $bindings[':environment'] = $this->environment;
        }
    }

    /**
     * returns the users with the most page views
     */
    public function topUsers(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        $q = '/* top users */ select user, count(route) as hits from data %where group by user order by hits desc';


        return $this->query($q, $params, $limit, $dateFrom, $dateTo);
    }

    /**
     * Shared rollup fast path for topCountries()/topBrowsers()/
     * topPlatforms() - three near-identical "day+is_bot+dimension -> hits"
     * breakdowns, each backed by its own narrow rollup table
     * (rollup_country/rollup_browser/rollup_platform - migration 8) rather
     * than one shared table; see that migration's own comment for why, and
     * for why - unlike pagesSummary()'s rollup_route - there's no per-route
     * breakdown here: only date-range + optional "hide bots" calls
     * (params a subset of just ['is_bot']) use this at all, a route/user/ip
     * -filtered call (Page/User Detail) keeps using each method's original
     * live query below, same as pagesSummary() already excludes those.
     *
     * Boundary-day-safe via rollupInteriorCoverage() - see its docblock and
     * docs/HISTORY.md #19 for why the first/last
     * calendar day of the range are always served live.
     *
     * $countColumn mirrors each call site's own original live-query COUNT()
     * target (topCountries counts "country", topBrowsers/topPlatforms count
     * "ip") rather than always counting $dimensionColumn - kept distinct on
     * purpose so this shared path can never subtly change which rows count
     * as a hit versus each method's pre-existing, unmodified live query.
     */
    private function topDimensionViaRollup(string $rollupTable, string $dimensionColumn, string $countColumn, int $limit, DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo, array $params): array
    {
        $coverage = $this->rollupInteriorCoverage($dateFrom, $dateTo);
        $liveQuery = "select {$dimensionColumn}, count({$countColumn}) as hits from data %where group by {$dimensionColumn}";

        if ($coverage === null) {
            $rows = $this->query($liveQuery, $params, null, $dateFrom, $dateTo);
        } else {
            [$interiorFromDay, $coveredToDay] = $coverage;
            $rows = $this->dimensionRollupPart($rollupTable, $dimensionColumn, $interiorFromDay, $coveredToDay, $params);

            $coveredStart = new DateTimeImmutable($interiorFromDay);
            $coveredEndExclusive = (new DateTimeImmutable($coveredToDay))->modify('+1 day');

            if ($dateFrom < $coveredStart) {
                $rows = array_merge($rows, $this->query($liveQuery, $params, null, $dateFrom, $coveredStart->modify('-1 second')));
            }
            if ($coveredEndExclusive <= $dateTo) {
                $rows = array_merge($rows, $this->query($liveQuery, $params, null, $coveredEndExclusive, $dateTo));
            }
        }

        $merged = [];
        foreach ($rows as $r) {
            $key = $r[$dimensionColumn];
            $merged[$key] = ($merged[$key] ?? 0) + (int) $r['hits'];
        }

        arsort($merged);
        $totalHits = array_sum($merged);
        $top = array_slice($merged, 0, $limit, true);

        $result = [];
        foreach ($top as $value => $hits) {
            $result[] = [
                $dimensionColumn => $value !== '' ? $value : 'unknown',
                'hits' => $hits,
                'share' => $totalHits > 0 ? round($hits * 100 / $totalHits, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * SUM(hits) over rollup_country/rollup_browser/rollup_platform for a
     * strictly-interior day range - $rollupTable/$dimensionColumn are
     * always one of the three hardcoded pairs topDimensionViaRollup() above
     * passes in, never user input, so directly interpolating them into the
     * SQL (table/column names can't be bound PDO placeholders) is safe.
     */
    private function dimensionRollupPart(string $rollupTable, string $dimensionColumn, string $fromDay, string $toDay, array $params): array
    {
        $where = ['day BETWEEN :from_day AND :to_day'];
        $bindings = [':from_day' => $fromDay, ':to_day' => $toDay];

        if (array_key_exists('is_bot', $params)) {
            $where[] = 'is_bot = :is_bot';
            $bindings[':is_bot'] = $params['is_bot'];
        }

        $this->appendEnvironmentFilter($where, $bindings);

        $sql = "
            SELECT {$dimensionColumn}, SUM(hits) as hits
            FROM {$rollupTable}
            WHERE " . implode(' AND ', $where) . "
            GROUP BY {$dimensionColumn}
        ";

        $s = $this->db->prepare($sql);
        $s->execute($bindings);

        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * returns the countries with the most page views
     */
    public function topCountries(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        if ($dateFrom && $dateTo && empty(array_diff(array_keys($params), ['is_bot']))) {
            return $this->topDimensionViaRollup('rollup_country', 'country', 'country', $limit, $dateFrom, $dateTo, $params);
        }

        // No SQL-level LIMIT here on purpose: this used to run a completely
        // separate totalPageViews() query, with the exact same %where/date-
        // range filter, purely to get the denominator for "share" below -
        // an entire second full pass over "data" just to recompute a total
        // this query's own GROUP BY already has all the ingredients for.
        // Fetching every group and summing "hits" in PHP gives the same
        // total for free. This is not more expensive than before: SQLite
        // has to aggregate and sort every matching row before it can even
        // know which ones rank in the top $limit, LIMIT only trims what
        // crosses back over into PHP afterwards - see docs/DATABASES.md.
        $q = 'select country, count(country) as hits from data %where group by country order by hits desc';

        $countries = $this->query($q, $params, null, $dateFrom, $dateTo);

        $totalPages = array_sum(array_column($countries, 'hits'));
        $countries = array_slice($countries, 0, $limit);

        $result = [];
        foreach ($countries as  $country) {
            if (empty($country['country'])) {
                $country['country'] = 'unknown';
            }
            $result[] = [
                'country' => $country['country'],
                'hits' => $country['hits'],
                'share' => round($country['hits'] * 100 / $totalPages, 2)
            ];
        }

        return $result;
    }


    /**
     * returns the total page views
     */
    public function totalPageViews(?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        $q = 'select count(route) as hits from data %where';

        return $this->query($q, $params, null, $dateFrom, $dateTo);
    }

    /**
     * returns the total number of unique visitors (distinct IPs)
     */
    public function totalUniqueVisitors(?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        if ($dateFrom && $dateTo && empty(array_diff(array_keys($params), ['is_bot']))) {
            return [['visitors' => $this->totalUniqueViaRollup('visitors', 'ip', $dateFrom, $dateTo, $params)]];
        }

        $q = 'select count(distinct ip) as visitors from data %where';

        return $this->query($q, $params, null, $dateFrom, $dateTo);
    }

    /**
     * returns the total number of unique logged in users
     */
    public function totalUniqueUsers(?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        if ($dateFrom && $dateTo && empty(array_diff(array_keys($params), ['is_bot']))) {
            return [['users' => $this->totalUniqueViaRollup('users', 'user', $dateFrom, $dateTo, $params)]];
        }

        $q = "select count(distinct user) as users from data %where";

        return $this->query($q, $params, null, $dateFrom, $dateTo);
    }

    /**
     * Shared rollup fast path for totalUniqueVisitors()/totalUniqueUsers() -
     * sums rollup_daily's already-per-day-exact "visitors"/"users" columns
     * over strictly-interior days, plus a live count(distinct ...) for
     * whatever boundary sliver(s) aren't covered (see
     * rollupInteriorCoverage()). Summing per-day exact distinct counts
     * across more than one day is the same deliberate, documented
     * approximation as pagesSummary()'s rollup path (see the comment on
     * rollup_daily in data/migrations/7.sql) - it can overcount a visitor
     * active on more than one of the summed days. $rollupColumn is
     * "visitors"/"users" (the rollup_daily column to sum), $liveColumn is
     * "ip"/"user" (what the live boundary query counts distinct values of).
     */
    private function totalUniqueViaRollup(string $rollupColumn, string $liveColumn, DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo, array $params): int
    {
        $coverage = $this->rollupInteriorCoverage($dateFrom, $dateTo);
        $liveQuery = "select count(distinct {$liveColumn}) as n from data %where";

        if ($coverage === null) {
            $rows = $this->query($liveQuery, $params, null, $dateFrom, $dateTo);

            return (int) ($rows[0]['n'] ?? 0);
        }

        [$interiorFromDay, $coveredToDay] = $coverage;

        $where = ['day BETWEEN :from_day AND :to_day'];
        $bindings = [':from_day' => $interiorFromDay, ':to_day' => $coveredToDay];
        if (array_key_exists('is_bot', $params)) {
            $where[] = 'is_bot = :is_bot';
            $bindings[':is_bot'] = $params['is_bot'];
        }
        $this->appendEnvironmentFilter($where, $bindings);
        $s = $this->db->prepare("SELECT SUM({$rollupColumn}) as n FROM rollup_daily WHERE " . implode(' AND ', $where));
        $s->execute($bindings);
        $sum = (int) ($s->fetch(PDO::FETCH_ASSOC)['n'] ?? 0);

        $coveredStart = new DateTimeImmutable($interiorFromDay);
        $coveredEndExclusive = (new DateTimeImmutable($coveredToDay))->modify('+1 day');

        if ($dateFrom < $coveredStart) {
            $rows = $this->query($liveQuery, $params, null, $dateFrom, $coveredStart->modify('-1 second'));
            $sum += (int) ($rows[0]['n'] ?? 0);
        }
        if ($coveredEndExclusive <= $dateTo) {
            $rows = $this->query($liveQuery, $params, null, $coveredEndExclusive, $dateTo);
            $sum += (int) ($rows[0]['n'] ?? 0);
        }

        return $sum;
    }

    /**
     * returns the browsers with the most pageviews
     */
    public function topBrowsers(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        if ($dateFrom && $dateTo && empty(array_diff(array_keys($params), ['is_bot']))) {
            return $this->topDimensionViaRollup('rollup_browser', 'browser', 'ip', $limit, $dateFrom, $dateTo, $params);
        }

        // See the comment on topCountries() above - same fix, same reason:
        // sum this query's own (unlimited) result instead of a second,
        // redundant totalPageViews() query.
        $q = 'select browser, count(ip) as hits from data %where group by browser order by hits desc';

        $browsers = $this->query($q, $params, null, $dateFrom, $dateTo);

        $totalPages = array_sum(array_column($browsers, 'hits'));
        $browsers = array_slice($browsers, 0, $limit);

        $result = [];
        foreach ($browsers as  $browser) {
            if (empty($browser['browser'])) {
                $browser['browser'] = 'unknown';
            }
            $result[] = [
                'browser' => $browser['browser'],
                'hits' => $browser['hits'],
                'share' => round($browser['hits'] * 100 / $totalPages, 2)
            ];
        }

        return $result;
    }

    /**
     * returns the platforms/os with the most pageviews
     */
    public function topPlatforms(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        if ($dateFrom && $dateTo && empty(array_diff(array_keys($params), ['is_bot']))) {
            return $this->topDimensionViaRollup('rollup_platform', 'platform', 'ip', $limit, $dateFrom, $dateTo, $params);
        }

        // See the comment on topCountries() above - same fix, same reason:
        // sum this query's own (unlimited) result instead of a second,
        // redundant totalPageViews() query.
        $q = 'select platform, count(ip) as hits from data %where group by platform order by hits desc';

        $platforms = $this->query($q, $params, null, $dateFrom, $dateTo);

        $totalPages = array_sum(array_column($platforms, 'hits'));
        $platforms = array_slice($platforms, 0, $limit);

        $result = [];
        foreach ($platforms as  $platform) {
            if (empty($platform['platform'])) {
                $platform['platform'] = 'unknown';
            }
            $result[] = [
                'platform' => $platform['platform'],
                'hits' => $platform['hits'],
                'share' => round($platform['hits'] * 100 / $totalPages, 2)
            ];
        }

        return $result;
    }

    /**
     * returns hits grouped by HTTP status, as three fixed, always-present
     * buckets (200, 404, other) rather than the raw distinct values found -
     * see collect()'s :http_code binding for what's actually ever written.
     * Fixed buckets keep this comparable across periods/installs even when
     * one is empty, and let a future, more precise status detection extend
     * what lands in "other" without changing this method's shape.
     */
    public function statusCodeSummary(?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        $buckets = [200 => 0, 404 => 0, 'other' => 0];

        if ($dateFrom && $dateTo && empty(array_diff(array_keys($params), ['is_bot']))) {
            $buckets = $this->statusCodeBucketsViaRollup($dateFrom, $dateTo, $params, $buckets);
        } else {
            $q = 'select http_code, count(route) as hits from data %where group by http_code';
            $rows = $this->query($q, $params, null, $dateFrom, $dateTo);
            $buckets = $this->addStatusCodeRows($rows, $buckets);
        }

        // This GROUP BY already had no LIMIT (every http_code bucket is
        // fetched), so - same fix as topCountries()/topBrowsers()/
        // topPlatforms() above - summing its own "hits" column replaces a
        // second, redundant totalPageViews() query that used to compute
        // the exact same number via its own separate full pass over "data".
        $totalPages = array_sum($buckets);

        $result = [];
        foreach ($buckets as $code => $hits) {
            $result[] = [
                'http_code' => $code,
                'hits' => $hits,
                'share' => $totalPages > 0 ? round($hits * 100 / $totalPages, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * Folds a set of {http_code, hits} rows (from either the live query or
     * the rollup-boundary live queries below) into the fixed 200/404/other
     * buckets - shared between statusCodeSummary()'s live path and its
     * rollup path so the "unknown/NULL code -> other" rule (see the
     * docblock above statusCodeSummary()) is defined exactly once.
     */
    private function addStatusCodeRows(array $rows, array $buckets): array
    {
        foreach ($rows as $row) {
            $code = (int) $row['http_code'];
            $key = array_key_exists($code, $buckets) ? $code : 'other';
            $buckets[$key] += (int) $row['hits'];
        }

        return $buckets;
    }

    /**
     * statusCodeSummary()'s rollup fast path - sums rollup_daily's already-
     * exact per-day http_200/http_404/http_other columns over strictly-
     * interior days (see rollupInteriorCoverage()), plus a live query for
     * whatever boundary sliver(s) aren't covered. Unlike the
     * visitors/users rollups elsewhere, this is not an approximation -
     * counting rows by http_code is as exact summed across days as summed
     * within one, same as "hits" everywhere else in this rollup work.
     */
    private function statusCodeBucketsViaRollup(DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo, array $params, array $buckets): array
    {
        $coverage = $this->rollupInteriorCoverage($dateFrom, $dateTo);
        $liveQuery = 'select http_code, count(route) as hits from data %where group by http_code';

        if ($coverage === null) {
            $rows = $this->query($liveQuery, $params, null, $dateFrom, $dateTo);

            return $this->addStatusCodeRows($rows, $buckets);
        }

        [$interiorFromDay, $coveredToDay] = $coverage;

        $where = ['day BETWEEN :from_day AND :to_day'];
        $bindings = [':from_day' => $interiorFromDay, ':to_day' => $coveredToDay];
        if (array_key_exists('is_bot', $params)) {
            $where[] = 'is_bot = :is_bot';
            $bindings[':is_bot'] = $params['is_bot'];
        }
        $this->appendEnvironmentFilter($where, $bindings);
        $s = $this->db->prepare('SELECT SUM(http_200) as http_200, SUM(http_404) as http_404, SUM(http_other) as http_other FROM rollup_daily WHERE ' . implode(' AND ', $where));
        $s->execute($bindings);
        $sums = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $buckets[200] += (int) ($sums['http_200'] ?? 0);
        $buckets[404] += (int) ($sums['http_404'] ?? 0);
        $buckets['other'] += (int) ($sums['http_other'] ?? 0);

        $coveredStart = new DateTimeImmutable($interiorFromDay);
        $coveredEndExclusive = (new DateTimeImmutable($coveredToDay))->modify('+1 day');

        if ($dateFrom < $coveredStart) {
            $rows = $this->query($liveQuery, $params, null, $dateFrom, $coveredStart->modify('-1 second'));
            $buckets = $this->addStatusCodeRows($rows, $buckets);
        }
        if ($coveredEndExclusive <= $dateTo) {
            $rows = $this->query($liveQuery, $params, null, $coveredEndExclusive, $dateTo);
            $buckets = $this->addStatusCodeRows($rows, $buckets);
        }

        return $buckets;
    }

    /**
     * returns the most recently viewed pages
     */
    public function recentPages(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        // $q = 'SELECT route, page_title, count(route) as hits, date FROM data GROUP BY route ORDER BY date DESC';

        $q = 'SELECT *, date(datetime(data.date), :offset) as day, time(datetime(data.date), :offset) as time  FROM data %where ORDER BY date DESC';

        return $this->query($q, $params, $limit, $dateFrom, $dateTo);
    }

    /**
     * returns recently viewd pages groupes by day
     */
    public function recentPagesByDay(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        $pages = $this->recentPages($limit, $dateFrom, $dateTo, $params);

        $result = [];
        foreach ($pages as $p) {
            if (!array_key_exists($p['day'], $result)) {
                $result[$p['day']] = [];
            }
            $result[$p['day']][] = $p;
        }

        return $result;
    }

    /**
     * returns the  statistics used for the charts
     */
    public function siteSummary(?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        if ($dateFrom && $dateTo && empty(array_diff(array_keys($params), ['is_bot']))) {
            return $this->siteSummaryViaRollup($dateFrom, $dateTo, $params);
        }

        return $this->siteSummaryLive($dateFrom, $dateTo, $params);
    }

    /**
     * siteSummary()'s original, unmodified live query, extracted into its
     * own method so both the "not rollup-eligible at all" path above and
     * siteSummaryViaRollup()'s boundary-sliver queries below can call the
     * exact same SQL rather than a second, hand-copied version of it.
     */
    private function siteSummaryLive(?DateTimeImmutable $dateFrom, ?DateTimeImmutable $dateTo, array $params): array
    {
        $hits = $this->query('SELECT date(datetime(date), :offset) as date, route, page_title, count(route) as hits FROM data %where GROUP BY date(datetime(date), :offset) ORDER BY date ASC', $params, null, $dateFrom, $dateTo);
        $visitors = $this->query('SELECT date(datetime(date), :offset) as date, route, page_title, ip, count(distinct ip) as hits FROM data %where GROUP BY date(datetime(date), :offset) ORDER BY date ASC',  $params, null, $dateFrom, $dateTo);
        $users = $this->query('SELECT date(datetime(date), :offset) as date, route, page_title, ip, count(distinct user) as hits FROM data %where GROUP BY date(datetime(date), :offset) ORDER BY date ASC',  $params, null, $dateFrom, $dateTo);

        return [
            'hits' => $hits,
            'visitors' => $visitors,
            'users' => $users,
        ];
    }

    /**
     * siteSummary()'s rollup fast path - the chart-data equivalent of
     * pagesSummaryViaRollup(). rollup_daily is already bucketed exactly the
     * way this method needs (one row per calendar day, matching
     * "date(datetime(date), :offset)"'s own bucketing - see rollupDay()'s
     * docblock), so strictly-interior days need only a single SUM()-per-day
     * query instead of a live GROUP BY over every matched row; the
     * first/last calendar day of the range still come from
     * siteSummaryLive() (see rollupInteriorCoverage(), docs/HISTORY.md
     * #19). No merge-by-key is needed here unlike
     * pagesSummaryViaRollup()/topDimensionViaRollup() above: interior rows
     * and boundary-sliver rows never share a calendar day, so they're
     * simply concatenated in chronological order (both sides are already
     * date-ascending).
     *
     * Interior rows carry only {date, hits} - no route/page_title/ip, since
     * rollup_daily doesn't track those; the Classic Admin chart widgets
     * (widgets/page-views.html.twig etc.) and Admin2's trend chart only
     * ever read `s.date`/`s.hits` from this array, so the missing keys on
     * rolled-up days are never actually referenced.
     */
    private function siteSummaryViaRollup(DateTimeImmutable $dateFrom, DateTimeImmutable $dateTo, array $params): array
    {
        $coverage = $this->rollupInteriorCoverage($dateFrom, $dateTo);
        if ($coverage === null) {
            return $this->siteSummaryLive($dateFrom, $dateTo, $params);
        }

        [$interiorFromDay, $coveredToDay] = $coverage;

        $where = ['day BETWEEN :from_day AND :to_day'];
        $bindings = [':from_day' => $interiorFromDay, ':to_day' => $coveredToDay];
        if (array_key_exists('is_bot', $params)) {
            $where[] = 'is_bot = :is_bot';
            $bindings[':is_bot'] = $params['is_bot'];
        }
        $this->appendEnvironmentFilter($where, $bindings);
        $s = $this->db->prepare('
            SELECT day, SUM(hits) as hits, SUM(visitors) as visitors, SUM(users) as users
            FROM rollup_daily
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY day ORDER BY day ASC
        ');
        $s->execute($bindings);
        $interiorRows = $s->fetchAll(PDO::FETCH_ASSOC);

        $hits = [];
        $visitors = [];
        $users = [];
        foreach ($interiorRows as $r) {
            $hits[] = ['date' => $r['day'], 'hits' => (int) $r['hits']];
            $visitors[] = ['date' => $r['day'], 'hits' => (int) $r['visitors']];
            $users[] = ['date' => $r['day'], 'hits' => (int) $r['users']];
        }

        $coveredStart = new DateTimeImmutable($interiorFromDay);
        $coveredEndExclusive = (new DateTimeImmutable($coveredToDay))->modify('+1 day');

        $before = ['hits' => [], 'visitors' => [], 'users' => []];
        if ($dateFrom < $coveredStart) {
            $before = $this->siteSummaryLive($dateFrom, $coveredStart->modify('-1 second'), $params);
        }
        $after = ['hits' => [], 'visitors' => [], 'users' => []];
        if ($coveredEndExclusive <= $dateTo) {
            $after = $this->siteSummaryLive($coveredEndExclusive, $dateTo, $params);
        }

        return [
            'hits' => array_merge($before['hits'], $hits, $after['hits']),
            'visitors' => array_merge($before['visitors'], $visitors, $after['visitors']),
            'users' => array_merge($before['users'], $users, $after['users']),
        ];
    }


    public function timeOnPage(?string $sid)
    {
        if (!$sid) {
            return;
        }
        $params = [
            'session_id' => $sid,
            'event' => 'ping',
        ];

        // $scopeByEnvironment=false: "events" has no "environment" column
        // (see query()'s docblock) - it's keyed to a "data" row via
        // session_id, which is already correctly scoped at collection time.
        return $this->query('select min(date) as start, max(date) as end, ROUND((JULIANDAY(max(date)) - JULIANDAY(min(date))) * 86400) AS seconds from events %where', $params, null, null, null, false)[0];
    }
}
