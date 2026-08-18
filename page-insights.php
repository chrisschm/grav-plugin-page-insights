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
    // every install (see docs/ARCHITECTURE.md, "Notable past bugs" #8).

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

            // Admin2 / grav-plugin-api (Grav 2.0+). No-op when the API
            // plugin isn't installed - Grav simply never fires these events.
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],

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
            $ips = array_map(function ($a) {
                return isset($a['ip']) ? $a['ip'] : '';
            }, $config['ignored_ips']);

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

            if (count($config['ignored_urls']) === 0 ) {
                return true;
            }

            $urls = array_map(function ($a) {
                return isset($a['url']) ?  $a['url'] : '';
            }, $config['ignored_urls']);

            $regexp = implode('|', $urls);

            return 0 === preg_match("#$regexp#", $url);
        }


        return true;
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

            $stats = new Stats($dbPath, $this->config());

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
            $this->grav['log']->addDebug('IP : ' . $ip);

            $this->grav['log']->addError('PageInsights plugin : ' . $e->getMessage() . ' - File: ' . $e->getFile() . ' - Line: ' . $e->getLine() . ' - Trace: ' . $e->getTraceAsString());
            // $this->grav['log']->addDebug('GEO DB : ' . self::GEO_DB);
            // $this->grav['log']->addDebug('STATS DB : ' . $dbPath);

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


        $stats = new Stats($dbPath, $this->config());
        $stats->collectEvent((string) $data['session_id'], (string) $data['event'], (string) $data['value']);

        exit();
    }

    public function onPageInitialized()
    {
        $uri = $this->grav['uri'];

        $collectorRoute =  self::PATH_ADMIN_STATS . self::PATH_EVENTS_COLLECTION;


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


        switch ($uri->path()) {
            case $collectorRoute:
                $this->collectEventData();
                break;
            default:
                $this->collectPageData();
                break;
        }
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
                'db' =>  new Stats($dbPath, $this->config()),
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

        try {
            (new GeoDbUpdater())->update($indexPath, $mode, $prebuiltUrl ?: null, $rawSourceUrl ?: null);
            $admin?->setMessage('Geo country database updated.', 'info');
        } catch (\Throwable $e) {
            $this->grav['log']->addError('PageInsights plugin: geo-db rebuild failed - ' . $e->getMessage());
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
            // (re)build action - see docs/ARCHITECTURE.md "Geolocation".
            $group->get('/geo-db/status', [$controller, 'geoDbStatus']);
            $group->post('/geo-db/rebuild', [$controller, 'rebuildGeoDb']);
        });
    }

    /**
     * `grav-plugin-api`'s `/translations` endpoint (consumed by
     * `window.__GRAV_I18N` - see docs/ARCHITECTURE.md "Admin2 i18n") resolves
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
     * docs/ARCHITECTURE.md "Notable past bugs" #11.
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
            $this->grav['log']->addError(
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
}
