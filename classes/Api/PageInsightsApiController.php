<?php

declare(strict_types=1);

namespace Grav\Plugin\PageInsights\Api;

use DateTimeImmutable;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\PageInsights\Stats;
use Grav\Plugin\PageInsightsPlugin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Exposes the Page Stats data layer (classes/Stats.php) as a set of read-only
 * REST endpoints consumed by the Admin2 (grav-plugin-admin2) dashboard page
 * shipped in admin-next/pages/page-insights.js.
 *
 * The stored/collected data itself is untouched - this class is purely a
 * presentation-layer bridge between the existing Stats class and the new
 * Grav 2.0 API/Admin2 architecture, which replaced the classic Admin's
 * onAdminDashboard / onAdminPage / plugins_hooked_nav mechanism used by
 * versions of this plugin prior to 2.8.
 */
class PageInsightsApiController extends AbstractApiController
{
    private const READ_PERMISSION = 'api.system.read';

    /**
     * GET /page-insights/overview
     *
     * Compact summary used to populate the dashboard's KPI cards and
     * "top N" widgets in a single request.
     */
    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $stats = $this->getStats();

        // Only "Recently viewed pages" supports the real-pages-only scope
        // filter for now (see getScopeFilter() doc comment) - top_pages and
        // everything else stay unfiltered.
        $recentFilter = $this->getScopeFilter($request);

        $totalViews = $stats->totalPageViews($dateFrom, $dateTo);
        $totalVisitors = $stats->totalUniqueVisitors($dateFrom, $dateTo);
        $totalUsers = $stats->totalUniqueUsers($dateFrom, $dateTo);

        return ApiResponse::create([
            'db' => $stats->dbStats(),
            'total_page_views' => (int) ($totalViews[0]['hits'] ?? 0),
            'total_unique_visitors' => (int) ($totalVisitors[0]['visitors'] ?? 0),
            'total_unique_users' => (int) ($totalUsers[0]['users'] ?? 0),
            'top_pages' => $stats->pagesSummary(5, $dateFrom, $dateTo),
            'top_countries' => $stats->topCountries(5, $dateFrom, $dateTo),
            'top_browsers' => $stats->topBrowsers(5, $dateFrom, $dateTo),
            'top_platforms' => $stats->topPlatforms(5, $dateFrom, $dateTo),
            'top_users' => $stats->topUsers(5, $dateFrom, $dateTo),
            'recent_pages' => $stats->recentPages(10, $dateFrom, $dateTo, $recentFilter),
            // Lets the dashboard adopt the admin-configured default scope
            // ('all'|'real') on first load without a separate request - see
            // admin-next/pages/page-insights.js, _loadDashboard().
            'default_pages_scope' => $this->getDefaultPagesScope(),
        ]);
    }

    /**
     * GET /page-insights/pages
     */
    public function pages(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $limit = $this->getLimit($request, 50);

        return ApiResponse::create([
            'pages' => $this->getStats()->pagesSummary($limit, $dateFrom, $dateTo),
        ]);
    }

    /**
     * GET /page-insights/pages/detail?route=/some/route
     */
    public function pageDetail(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        $route = $this->getQueryParam($request, 'route');
        if (!$route) {
            throw new ValidationException('A "route" query parameter is required.', [
                ['field' => 'route', 'message' => 'This field is required.'],
            ]);
        }

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $limit = $this->getLimit($request, 100);
        $stats = $this->getStats();
        $filter = ['route' => $route];

        $views = $stats->recentPages($limit, $dateFrom, $dateTo, $filter);

        return ApiResponse::create([
            'route' => $route,
            'hits' => count($views),
            'visitors' => count(array_unique(array_column($views, 'ip'))),
            'top_countries' => $stats->topCountries(5, $dateFrom, $dateTo, $filter),
            'top_browsers' => $stats->topBrowsers(5, $dateFrom, $dateTo, $filter),
            'top_platforms' => $stats->topPlatforms(5, $dateFrom, $dateTo, $filter),
            'views' => $views,
        ]);
    }

    /**
     * GET /page-insights/countries
     */
    public function countries(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $limit = $this->getLimit($request, 50);

        return ApiResponse::create([
            'countries' => $this->getStats()->topCountries($limit, $dateFrom, $dateTo),
        ]);
    }

    /**
     * GET /page-insights/browsers
     */
    public function browsers(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $limit = $this->getLimit($request, 50);

        return ApiResponse::create([
            'browsers' => $this->getStats()->topBrowsers($limit, $dateFrom, $dateTo),
        ]);
    }

    /**
     * GET /page-insights/platforms
     */
    public function platforms(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $limit = $this->getLimit($request, 50);

        return ApiResponse::create([
            'platforms' => $this->getStats()->topPlatforms($limit, $dateFrom, $dateTo),
        ]);
    }

    /**
     * GET /page-insights/users
     */
    public function users(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $limit = $this->getLimit($request, 50);

        return ApiResponse::create([
            'users' => $this->getStats()->topUsers($limit, $dateFrom, $dateTo),
        ]);
    }

    /**
     * GET /page-insights/users/detail?user=someuser  -or-  ?ip=1.2.3.4
     *
     * Accepts either a "user" or an "ip" query parameter. The "ip" variant
     * exists for anonymous visitors that have no username but are still
     * individually identifiable by IP (see admin-next/pages/page-insights.js,
     * "Recently viewed pages" - a row with no user falls back to showing/
     * linking the IP instead of a flat "(anonymous)", mirroring how the
     * classic-admin user-details.html.twig template detected an IP-shaped
     * "user" parameter and filtered by the ip column instead).
     */
    public function userDetail(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        $user = $this->getQueryParam($request, 'user');
        $ip = $this->getQueryParam($request, 'ip');
        if (!$user && !$ip) {
            throw new ValidationException('A "user" or "ip" query parameter is required.', [
                ['field' => 'user', 'message' => 'Either "user" or "ip" is required.'],
            ]);
        }

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $limit = $this->getLimit($request, 100);
        $stats = $this->getStats();

        $filter = $user ? ['user' => $user] : ['ip' => $ip];
        $views = $stats->recentPages($limit, $dateFrom, $dateTo, $filter);

        return ApiResponse::create([
            'user' => $user,
            'ip' => $ip,
            'hits' => count($views),
            'top_pages' => $stats->pagesSummary(5, $dateFrom, $dateTo, $filter),
            'views' => $views,
        ]);
    }

    /**
     * GET /page-insights/recent
     *
     * Powers the dashboard's "Recently viewed pages" card. Returns a flat,
     * newest-first list ('pages') used for the initial render and for the
     * "Load more" button (which simply re-requests this endpoint with a
     * larger `limit`), alongside the same data grouped by day ('by_day',
     * unchanged) for any future admin page wanting a day-by-day breakdown
     * akin to the classic-admin "Recently viewed pages" sub-page.
     */
    public function recent(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $limit = $this->getLimit($request, 50);
        $filter = $this->getScopeFilter($request);
        $stats = $this->getStats();

        return ApiResponse::create([
            'pages' => $stats->recentPages($limit, $dateFrom, $dateTo, $filter),
            'by_day' => $stats->recentPagesByDay($limit, $dateFrom, $dateTo, $filter),
        ]);
    }

    /**
     * GET /page-insights/summary  (dashboard)
     *  or  /page-insights/summary?route=...  /  ?user=...  /  ?ip=...  (detail views)
     *
     * Time series data (hits/visitors/users per day) used to draw the trend
     * chart on the dashboard, and - filtered by one of route/user/ip - the
     * equivalent per-entity trend chart on the Page/User Detail views.
     */
    public function summary(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::READ_PERMISSION);

        [$dateFrom, $dateTo] = $this->getDateRange($request);
        $filter = $this->getEntityFilter($request);

        return ApiResponse::create($this->getStats()->siteSummary($dateFrom, $dateTo, $filter));
    }

    /**
     * Builds the same style of equality-filter array Stats::query() expects
     * (['route' => ...] / ['user' => ...] / ['ip' => ...]) from whichever of
     * those query params is present. Returns [] (no filter) if none are -
     * that's what keeps the dashboard's own /summary call, which passes
     * none of them, working exactly as before.
     */
    private function getEntityFilter(ServerRequestInterface $request): array
    {
        $route = $this->getQueryParam($request, 'route');
        if ($route) {
            return ['route' => $route];
        }

        $user = $this->getQueryParam($request, 'user');
        if ($user) {
            return ['user' => $user];
        }

        $ip = $this->getQueryParam($request, 'ip');
        if ($ip) {
            return ['ip' => $ip];
        }

        return [];
    }

    /**
     * Builds the Stats::query() filter for the "only real pages" scope
     * ("Recently viewed pages" -> ?scope=real). Returns [] (no filter,
     * i.e. today's unfiltered behaviour) unless scope=real is explicitly
     * requested. Route whitelist comes from
     * PageInsightsPlugin::getRealPageRoutes() (Grav Pages-based, cached -
     * see there for why this doesn't live in Stats.php).
     *
     * @return array{route?: string[]}
     */
    private function getScopeFilter(ServerRequestInterface $request): array
    {
        if ($this->getQueryParam($request, 'scope') !== 'real') {
            return [];
        }

        $plugin = $this->getPlugin();

        return ['route' => $plugin ? $plugin->getRealPageRoutes() : []];
    }

    /**
     * The admin-configured default for the "Recently viewed pages" scope
     * toggle ('all'|'real'), sent along with /overview so the dashboard
     * can adopt it on first load - see getScopeFilter() and
     * admin-next/pages/page-insights.js.
     */
    private function getDefaultPagesScope(): string
    {
        $grav = Grav::instance();
        $default = (string) $grav['config']->get('plugins.page-insights.default_pages_scope', 'all');

        return $default === 'real' ? 'real' : 'all';
    }

    private function getPlugin(): ?PageInsightsPlugin
    {
        $grav = Grav::instance();

        return $grav['page-insights'] ?? null;
    }

    private function getStats(): Stats
    {
        $grav = Grav::instance();
        $config = (array) $grav['config']->get('plugins.page-insights');

        return new Stats($config['db'], $config);
    }

    private function getLimit(ServerRequestInterface $request, int $default): int
    {
        $limit = $this->getQueryParam($request, 'limit');

        return $limit !== null && (int) $limit > 0 ? (int) $limit : $default;
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function getDateRange(ServerRequestInterface $request): array
    {
        $from = $this->getQueryParam($request, 'date_from');
        $to = $this->getQueryParam($request, 'date_to');

        try {
            $dateFrom = $from ? new DateTimeImmutable($from) : null;
            $dateTo = $to ? new DateTimeImmutable($to) : null;
        } catch (\Throwable $e) {
            $dateFrom = null;
            $dateTo = null;
        }

        return [$dateFrom, $dateTo];
    }

    private function getQueryParam(ServerRequestInterface $request, string $name): ?string
    {
        $params = $request->getQueryParams();

        return isset($params[$name]) && $params[$name] !== '' ? (string) $params[$name] : null;
    }
}
