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

    const FORCE_MIGRATION_FLAG = '/../data/migrations/MUST_MIGRATE';

    public function __construct($dbPath, $config)
    {
        $this->config = $config;
        $this->botRegExp = implode('|', $this->config['bot_regexp']);
        $this->dt_offset = $this->config['datetime_offset'];

        $this->dbPath = new \SplFileInfo($dbPath);
        $migrate = !$this->dbPath->isWritable() || file_exists(__DIR__ . self::FORCE_MIGRATION_FLAG);
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

        unlink(__DIR__ . self::FORCE_MIGRATION_FLAG);
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
                ("ip", "country", "city", "region", "route", "page_title", "user", "date", "user_agent", "is_bot", "browser", "browser_version", "platform", "referer", "http_code")
             VALUES
                (:ip, :country, :city, :region, :route, :title, :user, :date, :user_agent, :is_bot, :browser, :browser_version, :platform, :referer, :http_code)
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

        $s->execute();

        return $this->db->lastInsertId();
    }

    private function query(string $q, array $params = [], ?int $limit = null, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null)
    {
        $where = [];
        $bindings = [];

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
        $q = 'SELECT route, page_title, count(route) as hits, count(distinct ip) as visitors, count(distinct user) as users FROM data %where GROUP BY page_title ORDER BY hits DESC';

        return $this->query($q, $params, $limit, $dateFrom, $dateTo);
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
     * returns the countries with the most page views
     */
    public function topCountries(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
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
        $q = 'select count(distinct ip) as visitors from data %where';

        return $this->query($q, $params, null, $dateFrom, $dateTo);
    }

    /**
     * returns the total number of unique logged in users
     */
    public function totalUniqueUsers(?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
        $q = "select count(distinct user) as users from data %where";

        return $this->query($q, $params, null, $dateFrom, $dateTo);
    }

    /**
     * returns the browsers with the most pageviews
     */
    public function topBrowsers(int $limit = 10, ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null, array $params = [])
    {
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
        $q = 'select http_code, count(route) as hits from data %where group by http_code';

        $rows = $this->query($q, $params, null, $dateFrom, $dateTo);

        // This GROUP BY already had no LIMIT (every http_code bucket is
        // fetched), so - same fix as topCountries()/topBrowsers()/
        // topPlatforms() above - summing its own "hits" column replaces a
        // second, redundant totalPageViews() query that used to compute
        // the exact same number via its own separate full pass over "data".
        $totalPages = array_sum(array_column($rows, 'hits'));

        $buckets = [200 => 0, 404 => 0, 'other' => 0];
        foreach ($rows as $row) {
            // (int) on a NULL http_code (rows written before this column
            // existed) yields 0, which correctly isn't a known bucket key
            // and falls through to 'other' below.
            $code = (int) $row['http_code'];
            $key = array_key_exists($code, $buckets) ? $code : 'other';
            $buckets[$key] += (int) $row['hits'];
        }

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
        $hits = $this->query('SELECT date(datetime(date), :offset) as date, route, page_title, count(route) as hits FROM data %where GROUP BY date(datetime(date), :offset) ORDER BY date ASC', $params, null, $dateFrom, $dateTo);
        $visitors = $this->query('SELECT date(datetime(date), :offset) as date, route, page_title, ip, count(distinct ip) as hits FROM data %where GROUP BY date(datetime(date), :offset) ORDER BY date ASC',  $params, null, $dateFrom, $dateTo);
        $users = $this->query('SELECT date(datetime(date), :offset) as date, route, page_title, ip, count(distinct user) as hits FROM data %where GROUP BY date(datetime(date), :offset) ORDER BY date ASC',  $params, null, $dateFrom, $dateTo);

        return [
            'hits' => $hits,
            'visitors' => $visitors,
            'users' => $users,
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

        return $this->query('select min(date) as start, max(date) as end, ROUND((JULIANDAY(max(date)) - JULIANDAY(min(date))) * 86400) AS seconds from events %where', $params)[0];
    }
}
