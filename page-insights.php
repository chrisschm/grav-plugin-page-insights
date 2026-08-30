<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use Grav\Common\Scheduler\Scheduler;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;;

use Grav\Plugin\PageInsights\AutoSchedule;
use Grav\Plugin\PageInsights\Geolocation\GeoDbUpdater;
use Grav\Plugin\PageInsights\Geolocation\Geolocation;
use Grav\Plugin\PageInsights\Geolocation\CountryLookup;
use Grav\Plugin\PageInsights\LocalizedDate;
use Grav\Plugin\PageInsights\RelativeDate;
use Grav\Plugin\PageInsights\Stats;
use Grav\Plugin\PageInsights\Api\PageInsightsApiController;
use RocketTheme\Toolbox\Event\EventSubscriberInterface;

/**
 * Class PageInsightsPlugin
 * @package Grav\Plugin
 */
class PageInsightsPlugin extends Plugin
{
    // const GEO_DB = __DIR__ . '/data/geolocation.sqlite';

    // Self-built, country-only geo index (see classes/Geolocation/CountryIndexBuilder.php).
    // Deliberately NOT shipped in the plugin's git repo/release archive and
    // NOT built automatically on install - it's built on demand from an
    // "Update now" action next to the Top Countries stat (Admin2: see
    // admin-next/pages/page-insights.js's _updateGeoDb(), calling
    // PageInsightsApiController::rebuildGeoDb(); Classic Admin: see
    // GEO_DB_REBUILD_FIELD handling in onAdminPage() below) - or, in a
    // later step, a Scheduler-friendly console command. Not a config-form
    // field in either UI: it's an action tied to this stat, not a setting.
    // Its *location* is a config value though (geo_db_index_path, default
    // user/data/page-insights/geo-country-index.bin - see page-insights.yaml
    // and section_geolocation in blueprints.yaml), same convention as `db`
    // below: deliberately outside this plugin's own directory so it
    // survives a GPM update, which replaces the whole plugin directory on
    // every install (see docs/HISTORY.md #8).

    // Classic Admin only (Admin2 goes through the REST endpoints in
    // PageInsightsApiController instead - grav-plugin-api isn't guaranteed
    // to be installed alongside classic Admin, and classic Admin has no
    // built-in AJAX/task convention this plugin otherwise uses). A plain
    // nonce-protected self-post from the Top Countries widget/page: see
    // widgets/geo-db-status.html.twig and the POST handling at the top of
    // onAdminPage().
    const GEO_DB_REBUILD_NONCE_ACTION = 'page-insights-geo-db-rebuild';
    const GEO_DB_REBUILD_FIELD = 'page-insights-geo-db-rebuild';

    const PATH_ADMIN_STATS = '/page-insights';
    const PATH_ADMIN_PAGE_DETAIL = '/page-details';
    const PATH_ADMIN_USER_DETAIL = '/user-details';
    const PATH_ADMIN_ALL_PAGES = '/all-pages';
    const PATH_ADMIN_TOP_COUNTRIES = '/top-countries';
    const PATH_ADMIN_TOP_BROWSERS = '/top-browsers';
    const PATH_ADMIN_TOP_PLATFORMS = '/top-platforms';
    const PATH_ADMIN_TOP_USERS = '/top-users';
    const PATH_EVENTS_COLLECTION = '/event-collection';
    const PATH_ADMIN_RECENTLY_VIEWED_PAGES = '/recently-viewed-pages';

    // Admin2 sidebar id / route / API prefix (see onApiSidebarItems,
    // onApiPluginPageInfo, onApiRegisterRoutes)
    const ADMIN2_PAGE_ID = 'page-insights';
    const ADMIN2_ROUTE = '/plugin/page-insights';
    const API_PREFIX = '/page-insights';

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                // Uncomment following line when plugin requires Grav < 1.7
                // ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ],
            // Classic Admin (Grav < 2.0 / Admin plugin). No-op when the
            // classic Admin plugin isn't installed.
            'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],

            // Registers the locale-aware date filter used by Classic
            // Admin's "Recently viewed pages" widget - see
            // onTwigExtensions(). Registered unconditionally like any
            // Grav plugin's Twig filters conventionally are (this is the
            // event Grav's own Twig service fires while building its one
            // shared Twig environment, not an Admin-only hook) - harmless
            // on the frontend, since no frontend template in this plugin
            // uses the filter.
            'onTwigExtensions' => ['onTwigExtensions', 0],

            // Admin2 / grav-plugin-api (Grav 2.0+). No-op when the API
            // plugin isn't installed - Grav simply never fires these events.
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],

            // Contributes open scan-detection alerts (see
            // registerScanDetectionJob()/onApiDashboardNotifications()) to
            // the Admin2 dashboard's notification banner. Same no-op-without-
            // the-api-plugin reasoning as the three events above.
            'onApiDashboardNotifications' => ['onApiDashboardNotifications', 0],

            // Grav-Core's own Scheduler (`bin/grav scheduler` / the Admin's
            // Scheduler status page). Registered unconditionally, like the
            // onApi* events above - this needs to fire in the CLI context
            // `bin/grav scheduler` runs in, which is neither isAdmin() nor
            // a normal frontend page load. See onSchedulerInitialized().
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Admin2 i18n bridge: must run unconditionally, on every single
        // request - see the docblock on mergeAdmin2TranslationAliases() for
        // why this can't live in an API-plugin-fired event instead.
        $this->mergeAdmin2TranslationAliases();

        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminDashboard' => ['onAdminDashboard', 1000],
                'onAdminPage' => ['onAdminPage', 0],
                'onTwigSiteVariables' => ['onTwigAdminVariables', 0],

            ]);
            return;
        }

        // Enable the main events we are interested in
        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 990],

        ]);
    }

    public function onAdminTwigTemplatePaths($event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/themes/admin/templates';
        $event['paths'] = $paths;
    }

    /**
     * Adds two locale-aware date Twig filters (see LocalizedDate's doc
     * comment and docs/ADMIN-UI.md, "Localized date formatting"/
     * docs/HISTORY.md for the full history). Both use
     * `$grav['language']->getLanguage()` - "active if set, else default" -
     * the same resolution `Language::translate()` itself falls back to, so
     * they always track whatever language the rest of this plugin's
     * `|t`-translated strings on the same page are already rendering in.
     *
     * - `page_insights_localized_day`: replaces the previous hardcoded
     *   `day|date('F jS')` in widgets/recently-viewed-pages.html.twig
     *   (always rendered English month names regardless of admin
     *   language).
     * - `page_insights_short_day`: replaces the previous completely
     *   unformatted raw `{{ s.date }}` (a bare 'YYYY-MM-DD' string) fed
     *   straight into Chart.js as an x-axis label in
     *   widgets/page-views.html.twig, unique-visitors.html.twig and
     *   unique-users.html.twig.
     * - `page_insights_localized_datetime`: replaces the previous fixed,
     *   non-localized `|date('Y-m-d H:i')` used for the three Unix-
     *   timestamp "next scheduled run"/"built at" status lines
     *   (stats.html.twig's `next_geo_db_update`/`next_auto_prune`,
     *   widgets/geo-db-status.html.twig's `builtAt`) - unlike the two
     *   filters above, these carry a time-of-day, not just a calendar day,
     *   which is why they need their own LocalizedDate::dateTime() rather
     *   than reusing longDay()/shortDay(). See docs/HISTORY.md for how
     *   this was found.
     */
    public function onTwigExtensions(): void
    {
        $twig = $this->grav['twig']->twig();
        $language = $this->grav['language'];

        $twig->addFilter(new \Twig\TwigFilter(
            'page_insights_localized_day',
            static function (string $isoDay) use ($language): string {
                return LocalizedDate::longDay($isoDay, (string) $language->getLanguage());
            }
        ));

        $twig->addFilter(new \Twig\TwigFilter(
            'page_insights_short_day',
            static function (string $isoDay) use ($language): string {
                return LocalizedDate::shortDay($isoDay, (string) $language->getLanguage());
            }
        ));

        $twig->addFilter(new \Twig\TwigFilter(
            'page_insights_localized_datetime',
            static function (int $timestamp) use ($language): string {
                return LocalizedDate::dateTime($timestamp, (string) $language->getLanguage());
            }
        ));
    }

    function getUserIP(): ?string
    {
        // Not every request context has a real client IP (e.g. `bin/grav`
        // CLI commands such as page-system-validator, which still fire
        // onPageInitialized). REMOTE_ADDR is simply unset there.
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;

        // CF-Connecting-IP, Client-IP and X-Forwarded-For are all
        // client-settable headers - trusting them unconditionally lets any
        // visitor choose what gets logged as their IP. Only consult them if
        // the site owner has explicitly opted in (their reverse proxy is
        // known/trusted); otherwise fall straight back to REMOTE_ADDR.
        // Importantly, we never write back to $_SERVER here: doing so would
        // persist the (possibly forged) value for the rest of the request,
        // affecting anything else that reads REMOTE_ADDR/HTTP_CLIENT_IP
        // afterwards (login throttling, other security plugins, ...).
        if ($this->config()['trust_proxy_headers'] ?? false) {
            $cloudflare = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
            $client     = $_SERVER['HTTP_CLIENT_IP'] ?? null;
            $forward    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
            // X-Forwarded-For can be a comma-separated chain; the leftmost
            // entry is the original client as seen by the first proxy hop.
            if (is_string($forward) && str_contains($forward, ',')) {
                $forward = trim(explode(',', $forward)[0]);
            }

            if (filter_var($cloudflare, FILTER_VALIDATE_IP)) {
                return $cloudflare;
            }
            if (filter_var($client, FILTER_VALIDATE_IP)) {
                return $client;
            }
            if (filter_var($forward, FILTER_VALIDATE_IP)) {
                return $forward;
            }
        }

        return $remote;
    }


    /**
     * returns the value for front matter property that controls processing of a page
     * or true otherwise.
     * We return true as the default behaviour is to be enabled for all pages
     *
     * eg:
     * page-insights:
     *      process: true
     *
     * @param array $headers
     * @return bool
     */
    private function isEnabledForPage(array $headers): bool
    {
        if (isset($headers['page-insights']['process'])) {
            return $headers['page-insights']['process'];
        }

        return true;
    }

    /**
     * returns false if IP (or regexp) are in the plugin config list
     *
     * @param string|null $ip
     * @return bool
     */
    private function isEnabledForIp(?string $ip): bool
    {
        if ($ip === null) {
            // No real client IP available (e.g. a CLI context like
            // `bin/grav page-system-validator`, which still fires
            // onPageInitialized) - nothing meaningful to log.
            return false;
        }

        $config  = $this->config();
        if (isset($config['ignored_ips']) && is_array($config['ignored_ips'])) {
            $ips = array_filter(array_map(function ($a) {
                return isset($a['ip']) ? trim((string) $a['ip']) : '';
            }, $config['ignored_ips']), function ($v) {
                return $v !== '';
            });

            if (empty($ips)) {
                // No usable entries left after dropping blanks (e.g. a
                // stray empty row left behind by Admin2's list-field
                // widget, which always renders one extra empty row to
                // add a new entry - saving the section without filling
                // it in persists that empty row too). Without this
                // guard, implode('|', $ips) would still contain an empty
                // alternative, and preg_match() treats an empty
                // alternative as matching every possible string at
                // position 0 - silently excluding every visitor instead
                // of none. See docs/HISTORY.md, bug #32.
                return true;
            }

            $regexp = implode('|', $ips);

            return 0 === preg_match("/$regexp/", $ip);
        }


        return true;
    }

        /**
     * returns false if Url (or regexp) are in the plugin config list
     *
     * @param string $url
     * @return bool
     */
    private function isEnabledForUrl(string $url): bool
    {
        $config  = $this->config();


        if (isset($config['ignored_urls']) && is_array($config['ignored_urls'])) {

            $urls = array_filter(array_map(function ($a) {
                return isset($a['url']) ? trim((string) $a['url']) : '';
            }, $config['ignored_urls']), function ($v) {
                return $v !== '';
            });

            if (empty($urls)) {
                // Same guard as isEnabledForIp() above, and for the same
                // reason - a stray blank entry (e.g. an empty row left
                // behind by Admin2's list-field widget) must not turn
                // into a regex alternative that matches every URL. See
                // docs/HISTORY.md, bug #32.
                return true;
            }

            $regexp = implode('|', $urls);

            return 0 === preg_match("#$regexp#", $url);
        }


        return true;
    }


    /**
     * The current request's Grav "environment" (Grav\Common\Config\Setup -
     * defaults to the request's hostname, with an admin-configurable alias
     * mechanism; 'cli' outside a web request) - see Stats::__construct()'s
     * docblock and docs/DATABASES.md, "Multisite (environment) scoping".
     * Used to scope a Stats instance's reads/writes to the site currently
     * being served, in a Grav multisite install sharing this plugin
     * installation across several sites (Codeberg Issue #3).
     */
    private function currentEnvironment(): ?string
    {
        $environment = (string) $this->grav['config']->get('environment');

        return $environment !== '' ? $environment : null;
    }

    /**
     * collecs stats about page data
     */
    private function collectPageData()
    {
        try {
            $config  = $this->config();
            $collectorRoute =  self::PATH_ADMIN_STATS . self::PATH_EVENTS_COLLECTION;

            $page = $this->grav['page'];
            $ip = $this->getUserIP();
            $geo = (new Geolocation(new CountryLookup($config['geo_db_index_path'])))->locate($ip);
            $uri = $this->grav['uri']->uri(false);
            $user = $this->grav['user'];
            $now = new DateTimeImmutable();
            $browser = $this->grav['browser'];
            $dbPath = $config['db'];

            if ($config['anonymize_ips']) {
                if (str_contains($ip, ':')) {
                    // IPv6 (truncate after second ':', i.e. after 4 bytes)
                    $ip = substr($ip, 0, strpos($ip, ':', strpos($ip, ':')+1)) . '::0';
                } else {
                    // IPv4 (truncate after second '.', i.e. after 2 bytes)
                    $ip = substr($ip, 0, strpos($ip, '.', strpos($ip, '.')+1)) . '.0.0';
                }
            }

            $stats = new Stats($dbPath, $this->config(), $this->currentEnvironment());

            $sessionId = $stats->collect($ip, $geo, $page, $uri, $user, $now, $browser);

            if ($config['log_time_on_page']) {
                $vars = json_encode([
                    'sid' => $sessionId,
                    'url' => $collectorRoute,
                    'config' => [
                        'ping' => $config["collector_ping_interval"],
                    ]
                ]);

                $this->grav['assets']->addInlineJs('var pageStats = ' . $vars, ['position' => 'before']);
                $this->grav['assets']->addJs('plugins://page-insights/js/ps.js', []);
            }
        } catch (\Throwable $e) {
            error_log($e->getmessage());
            $this->grav['log']->debug('IP : ' . $ip);

            $this->grav['log']->error('PageInsights plugin : ' . $e->getMessage() . ' - File: ' . $e->getFile() . ' - Line: ' . $e->getLine() . ' - Trace: ' . $e->getTraceAsString());
            // $this->grav['log']->debug('GEO DB : ' . self::GEO_DB);
            // $this->grav['log']->debug('STATS DB : ' . $dbPath);

            if (false === $config['ignore_errors']) {
                throw $e;
            }
        }
    }

    /**
     * Collect event data passed to us by front end
     */
    private function collectEventData(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            exit;
        }

        // Reject anything that isn't a scalar for these three fields (e.g. a
        // JSON array/object) here, with a clean 400 - rather than letting a
        // malformed payload hit Stats::collectEvent()'s typed string
        // parameters further down and trigger an unhandled TypeError.
        if (!isset($data['session_id']) || !is_scalar($data['session_id'])) {
            echo 'sid';
            http_response_code(400);
            exit();
        }

        if (!isset($data['event']) || !is_scalar($data['event'])) {
            echo 'event';
            http_response_code(400);
            exit();
        }
        if (!isset($data['value']) || !is_scalar($data['value'])) {
            echo 'value';
            http_response_code(400);
            exit();
        }

        $config  = $this->config();
        $dbPath = $config['db'];


        // No environment needed here: "events" has no "environment" column
        // at all (see Stats::query()'s docblock) - it's keyed to a "data"
        // row via session_id, which was already correctly scoped when that
        // row was written.
        $stats = new Stats($dbPath, $this->config());
        $stats->collectEvent((string) $data['session_id'], (string) $data['event'], (string) $data['value']);

        exit();
    }

    public function onPageInitialized()
    {
        $uri = $this->grav['uri'];

        $page = $this->grav['page'];
        if (false === $this->isEnabledForPage((array)$page->header())) {
            return;
        }

        $ip = $this->getUserIP();
        if (false === $this->isEnabledForIp($ip)) {
            return;
        }

        $url = (string) $uri;
        if (false === $this->isEnabledForUrl($url)) {
            return;
        }


        if ($this->isCollectorRequest($uri)) {
            $this->collectEventData();
        } else {
            $this->collectPageData();
        }
    }

    /**
     * Recognizes our own front-end collector call (the `time_on_page()`
     * ping in js/ps.js) structurally - POST plus a path ending in the
     * fixed PATH_EVENTS_COLLECTION suffix - instead of an exact match
     * against the full, renameable `PATH_ADMIN_STATS . PATH_EVENTS_COLLECTION`
     * path.
     *
     * An exact match breaks the moment that prefix changes underneath an
     * already-rendered page: a plugin rename (as happened 11.08.2026,
     * page-stats -> page-insights) or any stale cache still serving old
     * HTML embeds the *previous* collector URL in `pageStats.url`, so the
     * browser keeps POSTing pings to a path that no longer equals the
     * current constant. Those requests then fell through to the `default`
     * branch above, i.e. collectPageData() - which deliberately also logs
     * 404s (see Stats::collect(), 'notfound' template handling, meant for
     * tracking broken links) - so every such ping got counted as a real
     * page hit under the stale route (e.g. "/page-stats/event-collection"
     * showing up in Top Pages with a 404 behind it).
     *
     * Matching on the suffix alone is resilient to any future rename of
     * PATH_ADMIN_STATS and to base-path/language-prefix differences.
     * Requiring POST additionally rules out real (GET) page requests that
     * would coincidentally end in "/event-collection" - Grav frontend page
     * views are never POST.
     */
    private function isCollectorRequest($uri): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        $path = $uri->path();
        $suffix = self::PATH_EVENTS_COLLECTION;

        return substr($path, -strlen($suffix)) === $suffix;
    }


    public function onAdminDashboard()
    {
        $twig = $this->grav['twig'];

        // Dashboard
        $twig->plugins_hooked_nav['PLUGIN_PAGE_INSIGHTS.PAGE_STATS'] = [
            'route' => 'page-insights',
            'icon' => 'fa-line-chart',
            'authorize' => ['admin.login', 'admin.super'],
            'priority' => 900
        ];
    }

    public function onTwigAdminVariables(): void
    {
        $uri = $this->grav['uri'];
        $config = $this->config();
        $dbPath = $config['db'];

        $routes = $this->getPluginRoutes();

        if (in_array($uri->path(), $routes)) {
            $lookup = new CountryLookup($config['geo_db_index_path']);

            $this->grav['twig']->twig_vars['pageStats'] = [
                'db' =>  new Stats($dbPath, $this->config(), $this->currentEnvironment()),
                'urls' => $this->getPluginRoutes(),
                'geoDb' => [
                    'built' => $lookup->isAvailable(),
                    'builtAt' => $lookup->builtAt(),
                    'sourceDate' => $lookup->sourceDate(),
                    'ipv4Entries' => $lookup->ipv4EntryCount(),
                    'ipv6Entries' => $lookup->ipv6EntryCount(),
                    'rebuildField' => self::GEO_DB_REBUILD_FIELD,
                    'nonce' => Utils::getNonce(self::GEO_DB_REBUILD_NONCE_ACTION),
                    'nonceField' => self::GEO_DB_REBUILD_NONCE_ACTION . '-nonce',
                ],
            ];
        }
    }

    private function getPluginRoutes(): array
    {
        $config = $this->config();

        $adminHomeRule = rtrim($this->config->get('plugins.admin.route'), '/');
        $dashboardRoute = $adminHomeRule . '/dashboard';
        $adminRoute = $adminHomeRule . self::PATH_ADMIN_STATS;
        $pageStatsRoute = $adminRoute;
        $pageDetailsRoute = $adminRoute . self::PATH_ADMIN_PAGE_DETAIL;
        $userDetailsRoute = $adminRoute . self::PATH_ADMIN_USER_DETAIL;
        $allPagesRoute = $adminRoute . self::PATH_ADMIN_ALL_PAGES;
        $topCountriesRoute = $adminRoute . self::PATH_ADMIN_TOP_COUNTRIES;
        $topBrowsersRoute = $adminRoute . self::PATH_ADMIN_TOP_BROWSERS;
        $topPlatformsRoute = $adminRoute . self::PATH_ADMIN_TOP_PLATFORMS;
        $topUsersRoute = $adminRoute . self::PATH_ADMIN_TOP_USERS;
        $recentlyedViewdPagesRoute = $adminRoute . self::PATH_ADMIN_RECENTLY_VIEWED_PAGES;

        return [
            'adminHome' => $adminHomeRule,
            'dashboard' => $dashboardRoute,
            'base' => $pageStatsRoute,
            'pageDetails' =>  $pageDetailsRoute,
            'userDetails' => $userDetailsRoute,
            'allPages' => $allPagesRoute,
            'topCountries' => $topCountriesRoute,
            'topBrowsers' => $topBrowsersRoute,
            'topPlatforms' => $topPlatformsRoute,
            'topUsers' => $topUsersRoute,
            'recentlyedViewdPages' => $recentlyedViewdPagesRoute,
        ];
    }

    /**
     * Handles the "Update now" self-post from widgets/geo-db-status.html.twig
     * (Classic Admin only - see the doc comment on GEO_DB_REBUILD_FIELD and
     * the call site in onAdminPage()). Always redirects back afterwards
     * (POST-redirect-GET) so a page refresh never resubmits the rebuild.
     */
    private function handleGeoDbRebuildPost($uri): void
    {
        $admin = $this->grav['admin'] ?? null;
        $nonce = $uri->post(self::GEO_DB_REBUILD_NONCE_ACTION . '-nonce');

        if (!is_string($nonce) || !Utils::verifyNonce($nonce, self::GEO_DB_REBUILD_NONCE_ACTION)) {
            $admin?->setMessage('Could not update the geo country database: invalid or expired request.', 'error');
            $this->grav->redirect($uri->path());
        }

        $indexPath = (string) $this->config->get('plugins.page-insights.geo_db_index_path', 'user/data/page-insights/geo-country-index.bin');
        $mode = (string) $this->config->get('plugins.page-insights.geo_db_source_mode', 'prebuilt');
        $prebuiltUrl = (string) $this->config->get('plugins.page-insights.geo_db_prebuilt_url', '');
        $rawSourceUrl = (string) $this->config->get('plugins.page-insights.geo_db_source_url', '');

        // Best-effort actor name for the log lines below - $this->grav['user']
        // is the currently logged-in admin at this point (past the nonce
        // check above), but fall back gracefully rather than let a log call
        // itself break the actual rebuild if that assumption ever doesn't hold.
        $username = $this->grav['user']->username ?? 'unknown';

        try {
            $result = (new GeoDbUpdater())->update($indexPath, $mode, $prebuiltUrl ?: null, $rawSourceUrl ?: null);
            $admin?->setMessage('Geo country database updated.', 'info');

            // Info-level, deliberately - not an error, but worth having in
            // the log an admin is already asked to attach to a bug report
            // (see docs/GEOLOCATION.md), e.g. to confirm a
            // rebuild actually ran and when, without needing DB access.
            $this->grav['log']->info(sprintf(
                'PageInsights plugin: geo country index rebuilt manually via Classic Admin by %s - %d IPv4 + %d IPv6 entries (source date %s).',
                $username,
                $result['ipv4Entries'],
                $result['ipv6Entries'],
                $result['sourceDate'] ?? 'unknown'
            ));
        } catch (\Throwable $e) {
            $this->grav['log']->error('PageInsights plugin: geo-db rebuild failed (triggered by ' . $username . ') - ' . $e->getMessage());
            $admin?->setMessage('Could not update the geo country database: ' . $e->getMessage(), 'error');
        }

        $this->grav->redirect($uri->path());
    }

    public function onAdminPage(Event $event)
    {
        $uri = $this->grav['uri'];
        $routes = $this->getPluginRoutes();

        // Classic Admin "Update now" trigger (see GEO_DB_REBUILD_FIELD /
        // widgets/geo-db-status.html.twig). Plain nonce-protected self-post
        // rather than the core admin task framework or a JS/AJAX call -
        // this plugin's classic-admin side is otherwise fully server-
        // rendered (see onTwigAdminVariables()), and this is the one place
        // that needs a write action. Runs before the switch below so it
        // applies to POSTs from either "$routes['base']" (dashboard) or
        // "$routes['topCountries']" (dedicated page) - both render the same
        // widget. Never redirects on GET, only reacts to an actual POST.
        if (in_array($uri->path(), $routes) && $uri->post(self::GEO_DB_REBUILD_FIELD) !== null) {
            $this->handleGeoDbRebuildPost($uri);
        }

        $page = new Page;

        switch ($uri->path()) {
            case $routes['base']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/stats.md'));
                break;

            case $routes['pageDetails']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/page-details.md'));
                break;

            case $routes['userDetails']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/user-details.md'));
                break;

            case $routes['allPages']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/all-pages.md'));
                break;

            case $routes['topCountries']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/top-countries.md'));
                break;

            case $routes['topBrowsers']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/top-browsers.md'));
                break;

            case $routes['topPlatforms']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/top-platforms.md'));
                break;

            case $routes['topUsers']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/top-users.md'));
                break;

            case $routes['recentlyedViewdPages']:
                $page = $event['page'];
                $page->init(new \SplFileInfo(__DIR__ . '/pages/recently-viewed-pages.md'));
                break;
        }
    }

    /**
     * Returns the routes of every real, filesystem-based content page (i.e.
     * everything Grav's Pages object considers routable, under user/pages) -
     * used by PageInsightsApiController to filter "Recently viewed pages"
     * down to actual site content (?scope=real) instead of also showing
     * hits against assets, sitemap.xml, robots.txt, 404s, etc.
     *
     * Deliberately does NOT filter out unpublished pages - in practice
     * they're almost never hit by real visitors, so the extra check isn't
     * worth the added complexity (see docs/ARCHITECTURE.md).
     *
     * Cached and keyed to Grav's own pages cache ID (Pages::getPagesCacheId())
     * so this invalidates itself automatically whenever page content
     * changes - no manual cache-clearing needed, same pattern used by e.g.
     * the official relatedpages plugin.
     *
     * Lives here rather than in Stats.php on purpose: Stats.php is the
     * UI-/Grav-independent data layer and has no business knowing about
     * Grav's Pages object.
     *
     * @return string[]
     */
    public function getRealPageRoutes(): array
    {
        $pages = $this->grav['pages'];

        // Admin2/API requests run with Pages::disablePages() already
        // applied (Grav's own admin/API layer does this deliberately for
        // performance - most backend requests never need the full
        // frontend page tree). That makes Pages::init() take an
        // early-return branch that skips buildPages() entirely, so
        // routes(), getPagesCacheId() etc. silently stay empty/null - no
        // exception, nothing to catch. enablePages() flips the flag back
        // and re-runs init() properly; it's the documented counterpart to
        // disablePages() for exactly this situation. Grav's own internal
        // page cache (last_modified/hash-based) makes repeat calls cheap,
        // same as any normal frontend page load - this doesn't force a
        // fresh filesystem scan every time.
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        } else {
            $pages->init();
        }

        $cache = $this->grav['cache'];
        $cacheId = 'page-insights-real-routes-' . $pages->getPagesCacheId();
        $routes = $cache->fetch($cacheId);

        if ($routes === false) {
            $routes = array_keys($pages->routes());
            $cache->save($cacheId, $routes);
        }

        return $routes;
    }

    /**
     * Admin2 / grav-plugin-api: registers the REST endpoints consumed by the
     * Admin2 dashboard page (admin-next/pages/page-insights.js). Fired by the
     * API plugin's router; never fired if the API plugin isn't installed.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = PageInsightsApiController::class;

        $routes->group(self::API_PREFIX, function ($group) use ($controller) {
            $group->get('/overview', [$controller, 'overview']);
            $group->get('/summary', [$controller, 'summary']);
            $group->get('/pages', [$controller, 'pages']);
            $group->get('/pages/detail', [$controller, 'pageDetail']);
            $group->get('/countries', [$controller, 'countries']);
            $group->get('/browsers', [$controller, 'browsers']);
            $group->get('/platforms', [$controller, 'platforms']);
            $group->get('/users', [$controller, 'users']);
            $group->get('/users/detail', [$controller, 'userDetail']);
            $group->get('/recent', [$controller, 'recent']);

            // Geo country index: read-only status (used to render "last
            // updated" in the config tab) plus the actual admin-triggered
            // (re)build action - see docs/GEOLOCATION.md.
            $group->get('/geo-db/status', [$controller, 'geoDbStatus']);
            $group->post('/geo-db/rebuild', [$controller, 'rebuildGeoDb']);

            // On-demand database maintenance (vacuum / prune orphaned events /
            // prune data older than 1 year), triggered from the "Maintain
            // database" button next to the Admin2 dashboard's database-size
            // badge - see docs/MAINTENANCE.md "Admin2 database maintenance dialog".
            $group->post('/db/maintain', [$controller, 'maintainDb']);

            // Scan detection (see docs/ARCHITECTURE.md "Scan detection") -
            // the "Scan detection" Admin2 view's pattern list/alert feed.
            $group->get('/scan-patterns', [$controller, 'scanPatterns']);
            $group->post('/scan-patterns', [$controller, 'addScanPattern']);
            $group->patch('/scan-patterns/{id}', [$controller, 'setScanPatternEnabled']);
            $group->delete('/scan-patterns/{id}', [$controller, 'deleteScanPattern']);
            $group->get('/scan-alerts', [$controller, 'scanAlerts']);
        });
    }

    /**
     * `grav-plugin-api`'s `/translations` endpoint (consumed by
     * `window.__GRAV_I18N` - see docs/ADMIN-UI.md "Admin2 i18n") resolves
     * plugin strings via `Grav\Common\Config\Languages::flattenByLang()`, a
     * raw, unaliased lookup keyed by the *exact* admin locale code (e.g.
     * `"de-DE"`). This plugin's `languages/*.yaml` files use the legacy
     * short-code convention (`de.yaml`, not `de-DE.yaml`) that the rest of
     * the Grav 1.x plugin ecosystem and Weblate/Codeberg Translate expect
     * (see CONTRIBUTING.md "Translations") - so without this, every
     * `PLUGIN_PAGE_INSIGHTS.*` string is invisible to Admin2 regardless of
     * the admin's configured language, even though Classic Admin (and the
     * legacy `Language::translate()` call used for the sidebar label just
     * below) resolves the very same short-code files correctly.
     *
     * Fix: alias our already-loaded short-code strings into the BCP47
     * buckets Admin2 actually reads from, at runtime, in memory -
     * deliberately *not* by shipping duplicate `languages/de-DE.yaml`-style
     * files, which would silently drift out of sync with Weblate (the
     * source of truth stays `languages/<short-code>.yaml`). Mirrors the
     * pattern Grav core itself uses for theme languages
     * (`Themes::init()` -> `$this->grav['languages']->mergeRecursive()`).
     * Scoped to only our own `PLUGIN_PAGE_INSIGHTS` key so this can never
     * clobber another plugin's or theme's BCP47-keyed strings. Defensive by
     * design (silently no-ops on any unexpected shape) since this leans on
     * a Grav-internal API not covered by any compatibility guarantee.
     *
     * Deliberately called from `onPluginsInitialized()`, not from an
     * `onApi*` event: `grav-plugin-api`'s `ApiRouter::createDispatcher()`
     * wraps its whole route table in FastRoute's `cachedDispatcher()`
     * (`cache://api/route.cache`). Once that cache file exists - i.e. on
     * every request after the first - FastRoute returns the dispatcher
     * straight from the cache file and never re-invokes the route
     * definition callback at all, so `onApiRegisterRoutes` (an earlier,
     * broken version of this fix hooked there) simply never fires. See
     * docs/HISTORY.md #11.
     */
    private function mergeAdmin2TranslationAliases(): void
    {
        $languages = $this->grav['languages'] ?? null;
        if (!$languages) {
            return;
        }

        // Only the locales this plugin actually ships translations for.
        $aliases = [
            'de' => 'de-DE',
            'en' => 'en-US',
            'fr' => 'fr-FR',
        ];

        foreach ($aliases as $shortCode => $bcp47Code) {
            $strings = $languages[$shortCode]['PLUGIN_PAGE_INSIGHTS'] ?? null;
            if (is_array($strings) && $strings) {
                $languages->mergeRecursive([$bcp47Code => ['PLUGIN_PAGE_INSIGHTS' => $strings]]);
            }
        }
    }

    /**
     * Admin2: adds the "Page Stats" entry to the Admin2 sidebar.
     */
    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::ADMIN2_PAGE_ID,
            'plugin' => 'page-insights',
            'label' => $this->grav['language']->translate('PLUGIN_PAGE_INSIGHTS.PAGE_STATS'),
            'icon' => 'fa-line-chart',
            'route' => self::ADMIN2_ROUTE,
            'authorize' => ['admin.login', 'admin.super'],
            'priority' => 10,
        ];
        $event['items'] = $items;
    }

    /**
     * Admin2: contributes one dismissible "top" banner notification per
     * currently open scan-detection alert (see docs/ARCHITECTURE.md "Scan
     * detection") to the api plugin's dashboard notification feed
     * (DashboardController::notifications(), fired as
     * onApiDashboardNotifications - see that method's own doc comment for
     * the full mechanism: location grouping, dismiss/reappear_after,
     * per-user hide state). Deliberately independent of
     * data_auto_prune/notified_at - this always reflects live
     * "scan_alerts" state, not whatever the scheduled job has or hasn't
     * emailed yet (see registerScanDetectionJob()).
     *
     * No-op (no scan_patterns configured, feature disabled, or nothing
     * currently open) leaves $event['notifications'] untouched, same as
     * every other onApi* hook in this class when there's nothing to add.
     */
    public function onApiDashboardNotifications(Event $event): void
    {
        $config = $this->config();
        if (!$config['scan_detection']) {
            return;
        }

        $stats = new Stats((string) $config['db'], $config);
        $windowMinutes = (int) ($config['scan_detection_window_minutes'] ?? 10);
        $alerts = $stats->listOpenScanAlerts($windowMinutes);
        if (!$alerts) {
            return;
        }

        $notifications = $event['notifications'] ?? [];
        $notifications['top'] = $notifications['top'] ?? [];

        foreach ($alerts as $alert) {
            $notifications['top'][] = [
                'id' => 'page-insights-scan-alert-' . $alert['id'],
                'type' => 'warning',
                'title' => $this->grav['language']->translate('PLUGIN_PAGE_INSIGHTS.SCAN_ALERT_TITLE'),
                'message' => sprintf(
                    $this->grav['language']->translate('PLUGIN_PAGE_INSIGHTS.SCAN_ALERT_MESSAGE'),
                    $alert['ip'],
                    $alert['hit_count']
                ),
                // Reappears a day after being dismissed, in case the same IP
                // keeps probing - a one-time dismissal shouldn't silence a
                // genuinely ongoing attack for good.
                'reappear_after' => '+1 day',
            ];
        }

        $event['notifications'] = $notifications;
    }

    /**
     * Admin2: declares the "Page Stats" page as a component-mode page,
     * rendered by admin-next/pages/page-insights.js.
     */
    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'page-insights') {
            return;
        }

        $event['definition'] = [
            'id' => self::ADMIN2_PAGE_ID,
            'plugin' => 'page-insights',
            'title' => $this->grav['language']->translate('PLUGIN_PAGE_INSIGHTS.PAGE_STATS'),
            'icon' => 'fa-line-chart',
            'page_type' => 'component',
            'actions' => [],
        ];
    }

    /**
     * Registers the automatic, schedule-driven equivalents of the manual
     * "Update now" trigger (geo-db, see handleGeoDbRebuildPost()/
     * PageInsightsApiController::rebuildGeoDb()) and the manual `prune` CLI
     * command (see cli/PruneCommand.php). Fired by Grav-Core's Scheduler
     * whenever it actually runs - `bin/grav scheduler` (the site's single,
     * already-existing cron entry for Grav's built-in Scheduler, not
     * something this plugin needs its own crontab line for), the Admin's
     * Scheduler status page, or a Scheduler webhook.
     *
     * Both jobs are opt-in via config (geo_db_auto_update / data_auto_prune,
     * each "disabled"|"weekly"|"monthly" - see blueprints.yaml,
     * section_geolocation / section_data_retention). "disabled" is the
     * default for data_auto_prune specifically: deleting stats is
     * irreversible, so unlike the geo-db refresh it's opt-in, not
     * opt-out. When enabled, the actual weekday/day-of-month and
     * time-of-day are NOT admin-chosen - they're derived deterministically
     * per installation, see AutoSchedule for why and how.
     */
    public function onSchedulerInitialized(Event $event): void
    {
        /** @var Scheduler $scheduler */
        $scheduler = $event['scheduler'];
        $config = $this->config();

        $this->registerGeoDbAutoUpdateJob($scheduler, $config);
        $this->registerAutoPruneJob($scheduler, $config);
        $this->registerRollupBuildJob($scheduler, $config);
        $this->registerScanDetectionJob($scheduler, $config);
    }

    /**
     * Optional daily job that catches up rollup_daily/rollup_route (see
     * docs/DATABASES.md, "Rollups") to yesterday - same underlying
     * Stats::rollupDay() as `bin/plugin page-insights rollup:build`, run
     * automatically once enabled (rollup_auto_build: daily) instead of
     * requiring a cron entry an admin has to set up themselves. Only
     * "daily"/"disabled" are offered (see blueprints.yaml) - unlike the
     * geo-db-update/data-auto-prune jobs above, a rollup that's a week or a
     * month behind defeats its own purpose (the dashboard would fall straight
     * back to the original, un-rolled-up live query for that whole gap), so
     * there's no weekly/monthly option to offer here.
     */
    private function registerRollupBuildJob(Scheduler $scheduler, array $config): void
    {
        $mode = (string) ($config['rollup_auto_build'] ?? 'disabled');
        $cron = AutoSchedule::cronExpression(GRAV_ROOT, 'rollup-build', $mode);
        if ($cron === null) {
            return;
        }

        $dbPath = (string) $config['db'];

        $job = $scheduler->addFunction(
            function () use ($dbPath, $config) {
                // No environment passed: rollupDay() always (re)computes
                // every site's rows for a given day in one pass (grouped by
                // "environment" internally - see its own docblock), never
                // "the current site" - there is no such thing in a
                // scheduler/CLI context anyway.
                $stats = new Stats($dbPath, $config);
                $today = new DateTimeImmutable('today');
                $lastDone = $stats->rollupStatus();
                $from = $lastDone !== null ? (new DateTimeImmutable($lastDone))->modify('+1 day') : $today->modify('-1 day');

                $days = 0;
                $day = $from;
                while ($day < $today) {
                    $stats->rollupDay($day);
                    $days++;
                    $day = $day->modify('+1 day');
                }

                return "Rollup: {$days} Tag(e) aktualisiert (bis " . $today->modify('-1 day')->format('Y-m-d') . ").\n";
            },
            [],
            'page-insights-rollup-build'
        );
        $job->at($cron);
        $job->output('logs/page-insights-rollup-build.out');
    }

    private function registerGeoDbAutoUpdateJob(Scheduler $scheduler, array $config): void
    {
        $mode = (string) ($config['geo_db_auto_update'] ?? 'disabled');
        $cron = AutoSchedule::cronExpression(GRAV_ROOT, 'geo-db-update', $mode);
        if ($cron === null) {
            return;
        }

        $indexPath = (string) ($config['geo_db_index_path'] ?? 'user/data/page-insights/geo-country-index.bin');
        $sourceMode = (string) ($config['geo_db_source_mode'] ?? 'prebuilt');
        $prebuiltUrl = ((string) ($config['geo_db_prebuilt_url'] ?? '')) ?: null;
        $rawSourceUrl = ((string) ($config['geo_db_source_url'] ?? '')) ?: null;

        // A plain closure, not a static helper method: GeoDbUpdater::update()
        // already throws \RuntimeException on failure and deliberately
        // leaves it uncaught (see its own docblock) because every call site
        // wraps it - Job::exec() (Grav-Core) already catches \RuntimeException
        // around a scheduled callable and records the message as the job's
        // failure output, so no extra try/catch is needed here either.
        $job = $scheduler->addFunction(
            function () use ($indexPath, $sourceMode, $prebuiltUrl, $rawSourceUrl) {
                $result = (new GeoDbUpdater())->update($indexPath, $sourceMode, $prebuiltUrl, $rawSourceUrl);

                // Same reasoning as GeoDbUpdateCommand: report both dates,
                // not just sourceDate, so a scheduler log line never reads
                // like a mismatch against what the admin dashboards show.
                $builtAt = $result['builtAt'] ?? null;

                return sprintf(
                    "Geo-DB aktualisiert: %d IPv4- + %d IPv6-Eintraege (Datenstand: %s, erstellt: %s)\n",
                    $result['ipv4Entries'] ?? 0,
                    $result['ipv6Entries'] ?? 0,
                    $result['sourceDate'] ?? 'unbekannt',
                    $builtAt !== null ? date('Y-m-d H:i', $builtAt) : 'unbekannt'
                );
            },
            [],
            'page-insights-geo-db-update'
        );
        $job->at($cron);
        $job->output('logs/page-insights-geo-db-update.out');
    }

    private function registerAutoPruneJob(Scheduler $scheduler, array $config): void
    {
        $mode = (string) ($config['data_auto_prune'] ?? 'disabled');
        $cron = AutoSchedule::cronExpression(GRAV_ROOT, 'data-auto-prune', $mode);
        if ($cron === null) {
            return;
        }

        $olderThanRaw = (string) ($config['data_auto_prune_older_than'] ?? '365d');
        // Resolved fresh on every onSchedulerInitialized() call (i.e. every
        // `bin/grav scheduler` tick, whether or not the job is actually due
        // this minute) rather than once - "older than 365d" should always
        // mean 365 days before the moment the job *runs*, not before
        // whatever moment happened to register it.
        $cutoff = RelativeDate::resolve($olderThanRaw);
        if ($cutoff === null) {
            $this->grav['log']->error(
                "PageInsights plugin: ungueltiger Wert fuer data_auto_prune_older_than ('{$olderThanRaw}') - automatisches Prune wird uebersprungen."
            );
            return;
        }

        $dbPath = (string) $config['db'];

        // Deliberately never runs VACUUM itself, even though `prune`
        // (the manual CLI command) offers --vacuum for exactly this
        // combination - VACUUM takes a brief exclusive lock on the whole
        // database, and an admin opting into "delete old data automatically"
        // isn't necessarily also opting into "and briefly lock the database
        // on an unattended schedule". Use `bin/plugin page-insights vacuum`
        // (optionally its own cron/scheduler entry) if that's wanted too.
        $job = $scheduler->addFunction(
            function () use ($dbPath, $config, $cutoff) {
                // No environment passed: pruneData() operates across every
                // site's data at once (age-based maintenance, not "the
                // current site" - see currentEnvironment()'s docblock).
                $stats = new Stats($dbPath, $config);
                $deleted = $stats->pruneData($cutoff);

                return "Prune: {$deleted} Eintrag/Eintraege geloescht (aelter als {$cutoff->format('c')}).\n";
            },
            [],
            'page-insights-data-prune'
        );
        $job->at($cron);
        $job->output('logs/page-insights-data-prune.out');
    }

    /**
     * Optional scan-detection job (see docs/ARCHITECTURE.md "Scan
     * detection"): every five minutes, matches recently collected 404 hits
     * against the admin-curated "scan_patterns" list and raises an
     * "scan_alerts" row for any IP with too many distinct matches in too
     * short a window - see Stats::detectScans() for the actual logic, this
     * method is purely wiring.
     *
     * Deliberately NOT built on AutoSchedule (unlike the three jobs above):
     * AutoSchedule only derives a "disabled"/"daily"/"weekly"/"monthly"
     * point in time, never a sub-daily interval, since none of its other
     * callers need one - so this uses a fixed "*\/5 * * * *" cron
     * expression instead, gated by a plain enabled/disabled config flag
     * (scan_detection). The underlying `bin/grav scheduler` invocation
     * already runs every minute regardless (the site's one Scheduler cron
     * entry, same one every job here relies on), so this needs no
     * additional crontab line or admin setup beyond the config toggle -
     * see docs/MAINTENANCE.md "Scan detection" for the reasoning that ruled
     * out the Admin's "custom scheduled jobs" UI for this instead.
     *
     * Off by default, like data_auto_prune: unlike geo_db_auto_update
     * (which only refreshes a lookup file), this reads/writes new tables
     * and can send email - an admin should consciously opt in, plus decide
     * whether to populate scan_patterns at all first (`scan-patterns:import`
     * or the Admin2 "Scan detection" view).
     *
     * Alert email is sent directly from inside the job's own closure via
     * `Grav\Plugin\Email\Utils::sendEmail()` (see docs/HISTORY.md Bug #33)
     * - deliberately NOT via Grav-Core's `Scheduler\Job::email()`, unlike
     * what an admin-configured "custom scheduled jobs" entry would use.
     * `Job::email()` only takes effect inside `Job::postRun()`, which
     * unconditionally calls `Job::emailOutput()` whenever both `->output()`
     * and `->email()` are set on the job - and `emailOutput()` interpolates
     * `{$this->getCommand()}` into the email body uncaught. That's fine for
     * a job registered via `addCommand()` (a shell command string), but
     * every job in this class is registered via `addFunction()` instead, so
     * `getCommand()` returns the PHP `Closure` itself - and PHP cannot cast
     * a Closure to string, which throws an uncaught fatal `Error` (not a
     * catchable `RuntimeException`) outside any try/catch in Grav-Core's own
     * Scheduler code. That aborts the whole `bin/grav scheduler` run before
     * any later-registered job in the same tick executes, and never reaches
     * Grav's own log (Monolog never sees it) - visible only as a Plesk/cron
     * stderr error mail. Sending the alert email ourselves, only when
     * there's actually something to notify about, sidesteps that Grav-Core
     * bug entirely instead of depending on an upstream fix.
     */
    private function registerScanDetectionJob(Scheduler $scheduler, array $config): void
    {
        if (!$config['scan_detection']) {
            return;
        }

        $dbPath = (string) $config['db'];
        $windowMinutes = (int) ($config['scan_detection_window_minutes'] ?? 10);
        $threshold = (int) ($config['scan_detection_threshold'] ?? 5);
        $alertEmail = trim((string) ($config['scan_detection_alert_email'] ?? ''));

        $job = $scheduler->addFunction(
            function () use ($dbPath, $config, $windowMinutes, $threshold, $alertEmail) {
                // No environment passed: detectScans() deliberately looks
                // across every site sharing this installation - see its
                // own docblock.
                $stats = new Stats($dbPath, $config);
                $result = $stats->detectScans($windowMinutes, $threshold);

                // Only the alerts this run actually raised/extended that
                // haven't been emailed yet - a still-ongoing incident from
                // five minutes ago shouldn't generate a fresh email every
                // single run, only when it first crosses the threshold.
                $toNotify = array_filter($result['alerts'], fn ($a) => $a['notified_at'] === null);

                $lines = ["Scan-Erkennung: {$result['checked']} 404-Treffer im {$windowMinutes}-Minuten-Fenster geprueft.\n"];
                foreach ($result['alerts'] as $alert) {
                    $lines[] = sprintf(
                        "  IP %s: %d verschiedene verdaechtige Pfade (Schwelle: %d)%s\n    %s\n",
                        $alert['ip'],
                        $alert['hit_count'],
                        $threshold,
                        $alert['notified_at'] === null ? '' : ' (bereits gemeldet)',
                        implode(', ', $alert['matched_routes'])
                    );
                }

                if ($toNotify) {
                    $stats->markScanAlertsNotified(array_column($toNotify, 'id'));

                    // Sent directly, not via Scheduler\Job::email() - see
                    // this method's docblock for why. Same "is the email
                    // plugin installed" check emailOutput() itself uses
                    // internally, so no separate availability check is
                    // needed here either - silently does nothing without
                    // the (separate, official) email plugin installed.
                    if ($alertEmail !== '' && is_callable('Grav\Plugin\Email\Utils::sendEmail')) {
                        $subject = sprintf('Page Insights: %d neue(r) Scan-Alarm(e)', count($toNotify));
                        $emailLines = array_map(
                            fn ($a) => sprintf(
                                '<li>IP %s: %d verschiedene verdaechtige Pfade (Schwelle: %d)<br>%s</li>',
                                htmlspecialchars($a['ip']),
                                $a['hit_count'],
                                $threshold,
                                htmlspecialchars(implode(', ', $a['matched_routes']))
                            ),
                            $toNotify
                        );
                        $emailContent = "<h1>Page Insights: Scan-Erkennung</h1>\n<ul>\n" . implode("\n", $emailLines) . "\n</ul>\n";
                        \Grav\Plugin\Email\Utils::sendEmail($subject, $emailContent, $alertEmail);
                    }
                }

                return implode('', $lines);
            },
            [],
            'page-insights-scan-detection'
        );
        // Fixed 5-minute cron, not AutoSchedule - see this method's own
        // docblock for why.
        $job->at('*/5 * * * *');
        $job->output('logs/page-insights-scan-detection.out');
    }
}
