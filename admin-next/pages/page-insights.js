const TAG = window.__GRAV_PAGE_TAG || 'grav-page-insights--page-insights';

/**
 * Admin2 component-mode page for the Page Stats plugin.
 *
 * Talks to the REST endpoints registered by
 * classes/Api/PageInsightsApiController.php (see page-insights.php ->
 * onApiRegisterRoutes) to render an overview dashboard, plus small
 * lookup tools for a single page or a single user.
 *
 * This intentionally consolidates the nine separate classic-admin pages
 * (stats / page-details / user-details / all-pages / top-countries /
 * top-browsers / top-platforms / top-users / recently-viewed-pages) into
 * one dashboard with inline lookups, since Admin2 component pages are a
 * single route rather than a set of admin-theme templates.
 *
 * Page/User Detail sub-views: Admin2's client-side router only defines a
 * single dynamic segment for plugin pages (/plugin/[slug]) - no catch-all
 * for anything deeper, so an actual extra path segment would 404 client-
 * side on a hard reload. Instead, sub-views live on the exact same route
 * and are addressed purely via query string (?view=page-detail&route=...),
 * driven by plain history.pushState()/popstate (this custom element has no
 * access to SvelteKit's $app/navigation, only the native History API - but
 * that's what SvelteKit's own helpers wrap anyway, and this route has no
 * +page.ts load function tied to it, so query-string-only navigation never
 * triggers SvelteKit's own router). Page/User Detail reuse the same chart/
 * bars/table building blocks as the dashboard, filtered server-side by
 * route/user/ip (see PageInsightsApiController::pageDetail()/userDetail()/
 * summary()).
 */
class PageInsightsPage extends HTMLElement {
    #range = '30';
    #overview = null;
    #summary = null;
    #loading = false;
    #recentLimit = 10;
    #recentPages = [];
    #recentHasMore = true;
    #recentScope = 'all'; // 'all' | 'real' - which pages "Recently viewed pages" shows
    #recentScopeInitialized = false; // becomes true once the server-configured default has been adopted (see _loadDashboard)
    #hideBots = false; // dashboard-wide "Hide bots" toggle - unlike #recentScope, applies to every widget, not just one card
    #hideBotsInitialized = false; // becomes true once the server-configured default has been adopted (see _loadDashboard), or as soon as the user clicks the toggle themselves
    #geoStatus = null; // GET /page-insights/geo-db/status response, or null while unknown/failed
    #geoBusy = false; // true while a rebuild (POST /page-insights/geo-db/rebuild) is in flight
    #geoError = null; // last rebuild error message, cleared on the next successful load/rebuild
    #view = 'dashboard'; // 'dashboard' | 'page-detail' | 'user-detail'
    #viewParams = {};
    #onPopState = null;
    #unsubscribeLocale = null;

    connectedCallback() {
        this.attachShadow({ mode: 'open' });
        this._syncViewFromLocation();
        this.#onPopState = () => this._handlePopState();
        window.addEventListener('popstate', this.#onPopState);
        // Re-render on a live admin language switch (see window.__GRAV_I18N.subscribe()
        // doc comment in grav-admin-next) - everything here is plain template-string
        // HTML, not reactive, so without this the dashboard would keep showing
        // strings in the old language until a full page reload. Re-runs _load()
        // too rather than just _render(): the dashboard path re-renders cheaply
        // from cached #overview/#summary via _renderBody(), but the detail views
        // don't keep their last response around, so a full reload is the simplest
        // correct behaviour for the rare "switch language mid-session" case.
        this.#unsubscribeLocale = window.__GRAV_I18N?.subscribe(() => {
            this._render();
            this._load();
        }) ?? null;
        this._render();
        this._load();
    }

    disconnectedCallback() {
        if (this.#onPopState) window.removeEventListener('popstate', this.#onPopState);
        this.#unsubscribeLocale?.();
    }

    /**
     * Translate a PLUGIN_PAGE_INSIGHTS.* key via the Admin2 i18n bridge
     * (window.__GRAV_I18N - a read-only global admin-next itself installs,
     * see src/lib/stores/i18n.svelte.ts in getgrav/grav-admin-next), falling
     * back to the given English source string when the bridge is unavailable
     * (an older admin-next build without it) or the key has no translation.
     *
     * Plugin keys arrive via the bridge as plain, non-ICU strings sourced
     * from this repo's own languages/*.yaml (through the /translations API
     * endpoint) - has() is checked explicitly rather than trusting a
     * returned value, since an entirely unknown key still resolves to a
     * humanized fallback string instead of undefined.
     */
    _t(key, fallback) {
        const full = 'PLUGIN_PAGE_INSIGHTS.' + key;
        const bridge = window.__GRAV_I18N;
        if (!bridge || !bridge.has(full)) return fallback;
        return bridge.t(full);
    }

    /**
     * Like _t(), but for keys with %s placeholders. Deliberately NOT using
     * __GRAV_I18N.t()'s own ICU `params` support - plugin keys are the
     * plain (non-ICU) strings described in _t()'s doc comment, so t()
     * returns them verbatim without substitution. This instead mirrors the
     * sprintf-style substitution Classic Admin's Twig templates already get
     * for free from Grav's `|t(a, b, ...)` filter (see e.g.
     * GEO_DB_BUILT_STATUS in themes/admin/templates/widgets/geo-db-status.html.twig) -
     * same keys, same %s placeholder order, same positional args.
     */
    _tf(key, fallback, ...args) {
        const str = this._t(key, fallback);
        let i = 0;
        return str.replace(/%s/g, () => String(args[i++] ?? ''));
    }

    /**
     * Reads ?view=...&route=...|user=...|ip=... from the current URL into
     * #view/#viewParams. Falls back to 'dashboard' for anything malformed
     * (missing/unknown view, or a detail view without its required param)
     * rather than showing a broken detail shell.
     */
    _syncViewFromLocation() {
        const params = new URLSearchParams(location.search);
        const view = params.get('view');

        if (view === 'page-detail' && params.get('route')) {
            this.#view = 'page-detail';
            this.#viewParams = { route: params.get('route') };
            return;
        }
        if (view === 'user-detail' && (params.get('user') || params.get('ip'))) {
            this.#view = 'user-detail';
            this.#viewParams = params.get('user') ? { user: params.get('user') } : { ip: params.get('ip') };
            return;
        }
        this.#view = 'dashboard';
        this.#viewParams = {};
    }

    _handlePopState() {
        this._syncViewFromLocation();
        this._render();
        this._load();
    }

    /**
     * Internal navigation between the dashboard and a detail sub-view.
     * Pushes a real history entry (so the browser Back button works) but
     * never changes the path, only the query string - see class doc
     * comment for why that matters here.
     */
    _navigate(view, params = {}) {
        this.#view = view;
        this.#viewParams = params;

        let search = '';
        if (view !== 'dashboard') {
            search = `?${new URLSearchParams({ view, ...params }).toString()}`;
        }
        history.pushState({ view, params }, '', `${location.pathname}${search}`);

        this._render();
        this._load();
    }

    /**
     * Delegated click handling for internal nav links (data-nav="...",
     * optionally data-nav-route/-user/-ip). Real <a href> elements so
     * right-click / middle-click / ctrl-click "open in new tab" keeps
     * working (a fresh tab re-syncs from the URL via _syncViewFromLocation
     * on connectedCallback); a plain left click is intercepted to do an
     * in-place SPA navigation instead of a full page reload.
     */
    _bindNavLinks(root) {
        root.querySelectorAll('[data-nav]').forEach((el) => {
            el.addEventListener('click', (e) => {
                if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                e.preventDefault();
                const view = el.dataset.nav;
                const params = {};
                if (el.dataset.navRoute) params.route = el.dataset.navRoute;
                if (el.dataset.navUser) params.user = el.dataset.navUser;
                if (el.dataset.navIp) params.ip = el.dataset.navIp;
                this._navigate(view, params);
            });
        });
    }

    _apiUrl(path) {
        const base = window.__GRAV_API_SERVER_URL || '';
        const prefix = window.__GRAV_API_PREFIX || '/api/v1';
        return `${base}${prefix}${path}`;
    }

    _apiHeaders() {
        const headers = {};
        const token = window.__GRAV_API_TOKEN;
        if (token) headers['X-API-Token'] = token;
        return headers;
    }

    async _apiGet(path, params = {}) {
        const query = new URLSearchParams(params).toString();
        const url = this._apiUrl(path) + (query ? `?${query}` : '');
        const resp = await fetch(url, { headers: this._apiHeaders() });
        if (!resp.ok) {
            const body = await resp.json().catch(() => ({}));
            throw new Error(body.detail || body.title || this._tf('ADMIN2.REQUEST_FAILED', 'Request failed (%s)', resp.status));
        }
        const json = await resp.json();
        return json.data !== undefined ? json.data : json;
    }

    async _apiPost(path, body) {
        const hasBody = body !== undefined;
        const resp = await fetch(this._apiUrl(path), {
            method: 'POST',
            headers: hasBody ? { ...this._apiHeaders(), 'Content-Type': 'application/json' } : this._apiHeaders(),
            body: hasBody ? JSON.stringify(body) : undefined,
        });
        if (!resp.ok) {
            const errorBody = await resp.json().catch(() => ({}));
            throw new Error(errorBody.detail || errorBody.title || this._tf('ADMIN2.REQUEST_FAILED', 'Request failed (%s)', resp.status));
        }
        const json = await resp.json().catch(() => ({}));
        return json.data !== undefined ? json.data : json;
    }

    /**
     * @returns {{from: Date|null, to: Date|null}} 'all time' is represented
     * as {from: null, to: null} - there's no meaningful start date to zero-fill
     * a chart from.
     */
    _currentDateRange() {
        if (this.#range === 'all') return { from: null, to: null };
        const days = parseInt(this.#range, 10);
        const to = new Date();
        const from = new Date();
        from.setDate(from.getDate() - days);
        return { from, to };
    }

    _dateRangeParams() {
        const { from, to } = this._currentDateRange();
        if (!from || !to) return {};
        return {
            date_from: from.toISOString(),
            date_to: to.toISOString(),
        };
    }

    /**
     * Query params for the "Hide bots" toggle (see #hideBots) - merged into
     * every request this component makes (dashboard overview/summary, Page
     * Detail, User Detail, "Load more") since, unlike the "Recently viewed
     * pages" scope filter, this is meant to affect the whole view, not one
     * card. Server side: PageInsightsApiController::getBotFilter().
     */
    _botFilterParams() {
        return this.#hideBots ? { hide_bots: '1' } : {};
    }

    async _load() {
        if (this.#view === 'page-detail') return this._loadPageDetail();
        if (this.#view === 'user-detail') return this._loadUserDetail();
        return this._loadDashboard();
    }

    async _loadDashboard() {
        this.#loading = true;
        this.#recentLimit = 10;
        this._renderBody();

        const dateParams = this._dateRangeParams();
        // Scope and bot-filter params are only sent once we know the actual
        // value to request - on the very first load (before the
        // server-configured defaults are known, see below) both are
        // omitted so this first /overview call comes back unfiltered,
        // matching today's default behaviour for every install that hasn't
        // touched "default_pages_scope"/"default_hide_bots".
        const botParams = this.#hideBotsInitialized ? this._botFilterParams() : {};
        const overviewParams = {
            ...dateParams,
            ...botParams,
            ...(this.#recentScopeInitialized && this.#recentScope === 'real' ? { scope: 'real' } : {}),
        };
        const [overviewResult, summaryResult, geoStatusResult] = await Promise.allSettled([
            this._apiGet('/page-insights/overview', overviewParams),
            this._apiGet('/page-insights/summary', { ...dateParams, ...botParams }),
            this._apiGet('/page-insights/geo-db/status'),
        ]);

        this.#overview = overviewResult.status === 'fulfilled' ? overviewResult.value : null;
        this.#summary = summaryResult.status === 'fulfilled' ? summaryResult.value : null;
        this.#recentPages = this.#overview?.recent_pages || [];
        this.#recentHasMore = this.#recentPages.length >= this.#recentLimit;
        // Non-fatal, silently: an older grav-plugin-api without this route
        // (added alongside the self-built geo index) would 404 here, and
        // that must not block the rest of the dashboard from rendering -
        // the "Top countries" card just falls back to showing no status/
        // update control (see _renderBody()).
        this.#geoStatus = geoStatusResult.status === 'fulfilled' ? geoStatusResult.value : null;

        // First load only: adopt the admin-configured defaults for scope
        // and hide-bots. The /overview call above already ran without
        // either param, so if either default turns out to be the
        // non-default choice, something needs re-fetching:
        //  - hide-bots default 'on' affects every widget on the dashboard
        //    (KPIs, every top list, the trend chart), not just one card -
        //    re-run the whole load, which supersedes the narrower
        //    recent-only reload below (both defaults are already applied
        //    by the time that re-run builds its params).
        //  - scope default 'real' (and hide-bots still 'off') only affects
        //    "Recently viewed pages" - re-fetch just that card, as before.
        if (!this.#recentScopeInitialized || !this.#hideBotsInitialized) {
            const newScope = this.#overview?.default_pages_scope === 'real' ? 'real' : 'all';
            const newHideBots = this.#overview?.default_hide_bots === true;
            const scopeChanged = !this.#recentScopeInitialized && newScope === 'real';
            const botsChanged = !this.#hideBotsInitialized && newHideBots === true;

            this.#recentScopeInitialized = true;
            this.#hideBotsInitialized = true;
            this.#recentScope = newScope;
            this.#hideBots = newHideBots;
            this._highlightHideBots();

            if (botsChanged) {
                this.#loading = true;
                this._renderBody();
                return this._loadDashboard();
            }
            if (scopeChanged) {
                await this._reloadRecent();
            }
        }

        if (overviewResult.status === 'rejected') {
            this._error = overviewResult.reason?.message || this._t('ADMIN2.ERROR_LOAD_DASHBOARD', 'Could not load page stats');
            window.__GRAV_TOAST?.error(this._error);
        } else if (summaryResult.status === 'rejected') {
            // Non-fatal: the KPI numbers and top lists come from /overview
            // and still work, only the trend sparklines are missing.
            window.__GRAV_TOAST?.error(summaryResult.reason?.message || this._t('ADMIN2.ERROR_LOAD_TREND', 'Could not load trend data'));
        }

        this.#loading = false;
        this._renderBody();
    }

    /**
     * Triggers POST /page-insights/geo-db/rebuild (admin-only, requires
     * api.system.write - see PageInsightsApiController::rebuildGeoDb()).
     * Synchronous on the server: the button stays disabled and shows a
     * spinner-ish label until the response comes back, since the RIR source
     * file is tens of MB of text and this can take a while. Only the geo-db
     * status re-renders afterwards - existing stats rows already have their
     * country code stored from when they were collected, so a rebuild only
     * affects country lookups for future page hits, not past ones.
     */
    async _updateGeoDb() {
        if (this.#geoBusy) return;
        this.#geoBusy = true;
        this.#geoError = null;
        this._renderBody();

        try {
            await this._apiPost('/page-insights/geo-db/rebuild');
            this.#geoStatus = await this._apiGet('/page-insights/geo-db/status');
            window.__GRAV_TOAST?.success(this._t('ADMIN2.GEO_DB_UPDATED_TOAST', 'Geo country database updated.'));
        } catch (err) {
            this.#geoError = err?.message || this._t('ADMIN2.ERROR_GEO_DB_UPDATE', 'Could not update the geo country database.');
            window.__GRAV_TOAST?.error(this.#geoError);
        } finally {
            this.#geoBusy = false;
            this._renderBody();
        }
    }

    /**
     * Opens the "Maintain database" dialog (button next to the database-size
     * badge, see _renderDashboardShell()) via window.__GRAV_DIALOGS.form() -
     * a single modal with a warning description plus a five-option select,
     * deliberately no separate confirm() step: the warning is already shown
     * right above the choice, and the dialog's own submit button is the
     * confirmation, keeping this to the one dialog that was asked for rather
     * than an extra safety click. Calls POST /page-insights/db/maintain (see
     * PageInsightsApiController::maintainDb()) with the chosen action, then
     * refreshes the database-size badge and shows a toast with the result.
     */
    async _openDbMaintainDialog() {
        const dialogs = window.__GRAV_DIALOGS;
        if (!dialogs?.form) {
            window.__GRAV_TOAST?.error(this._t('ADMIN2.ERROR_DB_MAINTAIN_UNAVAILABLE', 'Database maintenance is not available in this Admin2 version.'));
            return;
        }

        const result = await dialogs.form({
            title: this._t('ADMIN2.DB_MAINTAIN_TITLE', 'Maintain database'),
            description: this._t('ADMIN2.DB_MAINTAIN_WARNING', 'Deleting statistics data is permanent and cannot be undone.'),
            fields: [
                {
                    name: 'action',
                    type: 'select',
                    label: this._t('ADMIN2.DB_MAINTAIN_ACTION_LABEL', 'Action'),
                    options: [
                        { value: 'vacuum', label: this._t('ADMIN2.DB_MAINTAIN_ACTION_VACUUM', 'Free up disk space only (no data is deleted)') },
                        { value: 'prune_orphans', label: this._t('ADMIN2.DB_MAINTAIN_ACTION_PRUNE_ORPHANS', 'Delete orphaned events') },
                        { value: 'prune_old', label: this._t('ADMIN2.DB_MAINTAIN_ACTION_PRUNE_OLD', 'Delete data older than 1 year') },
                        { value: 'prune_bots', label: this._t('ADMIN2.DB_MAINTAIN_ACTION_PRUNE_BOTS', 'Delete bot traffic') },
                        { value: 'prune_notfound', label: this._t('ADMIN2.DB_MAINTAIN_ACTION_PRUNE_NOTFOUND', 'Delete 404 (not found) hits') },
                    ],
                },
            ],
            submitLabel: this._t('ADMIN2.DB_MAINTAIN_SUBMIT', 'Run'),
        }).catch(() => null); // Admin2 version without __GRAV_DIALOGS.form() support, or the modal itself failed to mount.

        const action = result?.action;
        if (!action) return; // cancelled, or the dialog bridge rejected

        await this._runDbMaintenance(action);
    }

    /**
     * Runs the chosen database-maintenance action and reports the result.
     * Disables the triggering button directly via the DOM (rather than a new
     * private field + full _renderBody(), which only re-renders the dynamic
     * `.body`/`.db-size` parts, not the static toolbar button) since this is
     * the only place that needs the busy state.
     */
    async _runDbMaintenance(action) {
        const btn = this.shadowRoot.querySelector('.db-maintain-btn');
        const originalLabel = btn?.textContent;
        if (btn) {
            btn.disabled = true;
            btn.textContent = this._t('ADMIN2.DB_MAINTAIN_RUNNING', 'Running…');
        }

        try {
            const data = await this._apiPost('/page-insights/db/maintain', { action });

            if (this.#overview) this.#overview.db = data.db;
            this._renderBody();

            const beforeMb = Math.round((data.size_before / 1024 / 1024) * 10) / 10;
            const afterMb = Math.round((data.size_after / 1024 / 1024) * 10) / 10;

            if (data.deleted !== null && data.deleted !== undefined) {
                window.__GRAV_TOAST?.success(this._tf(
                    'ADMIN2.DB_MAINTAIN_TOAST_DELETED',
                    '%s row(s) deleted. Database size: %s MB → %s MB.',
                    data.deleted, beforeMb, afterMb
                ));
            } else {
                window.__GRAV_TOAST?.success(this._tf(
                    'ADMIN2.DB_MAINTAIN_TOAST_VACUUM',
                    'Database size: %s MB → %s MB.',
                    beforeMb, afterMb
                ));
            }
        } catch (err) {
            window.__GRAV_TOAST?.error(err?.message || this._t('ADMIN2.ERROR_DB_MAINTAIN', 'Could not perform database maintenance.'));
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = originalLabel;
            }
        }
    }

    _detailBodyEl() {
        return this.shadowRoot.querySelector('.detail-body');
    }

    /**
     * Page Detail: KPI + trend chart + Top countries/browsers/platforms,
     * all filtered server-side to this one route, plus the raw recent
     * views list. Mirrors the classic-admin 1.7 page-details.html.twig
     * widget set (see grav-chat-2026-07-26-page-insights-blueprint-config-tabs.md,
     * Teil 2) using the same building blocks as the dashboard.
     */
    async _loadPageDetail() {
        const body = this._detailBodyEl();
        if (!body) return;
        body.innerHTML = `<div class="state">${this._esc(this._t('ADMIN2.LOADING', 'Loading…'))}</div>`;

        const route = this.#viewParams.route;
        const params = { ...this._dateRangeParams(), ...this._botFilterParams(), route, limit: 100 };
        const [detailResult, summaryResult] = await Promise.allSettled([
            this._apiGet('/page-insights/pages/detail', params),
            this._apiGet('/page-insights/summary', params),
        ]);

        if (detailResult.status === 'rejected') {
            body.innerHTML = `<div class="state error">${this._esc(detailResult.reason?.message || this._t('ADMIN2.ERROR_LOAD_PAGE_DETAIL', 'Could not load page detail'))}</div>`;
            return;
        }

        const d = detailResult.value;
        const summary = summaryResult.status === 'fulfilled' ? summaryResult.value : null;
        const { from, to } = this._currentDateRange();
        const hitsSeries = this._buildDailySeries(summary?.hits, from, to);

        body.innerHTML = `
            <div class="charts">
                ${this._chartCard(this._t('PAGE_VIEWS_WIDGET', 'Page views'), d.hits, hitsSeries, 'var(--primary)')}
            </div>
            <div class="grid">
                <div class="card">
                    <h3>${this._esc(this._t('TOP_COUNTRIES', 'Top countries'))}</h3>
                    ${this._bars(d.top_countries, 'country')}
                </div>
                <div class="card">
                    <h3>${this._esc(this._t('TOP_BROWSERS', 'Top browsers'))}</h3>
                    ${this._bars(d.top_browsers, 'browser')}
                </div>
                <div class="card">
                    <h3>${this._esc(this._t('TOP_PLATFORMS', 'Top platforms'))}</h3>
                    ${this._bars(d.top_platforms, 'platform')}
                </div>
                <div class="card wide">
                    <h3>${this._esc(this._tf('ADMIN2.RECENT_VIEWS_HEADING', 'Recent views (%s hits, %s unique visitors)', d.hits, d.visitors))}</h3>
                    ${this._table(
                        [this._t('TABLE_USER', 'User'), this._t('TABLE_BROWSER', 'Browser'), this._t('TABLE_PLATFORM', 'Platform'), this._t('TABLE_DATE', 'Date')],
                        (d.views || []).map((v) => [
                            this._userCellHtml({ user: v.user, ip: v.ip }),
                            this._esc(v.browser || this._t('GEO_DB_UNKNOWN', 'unknown')),
                            this._esc(v.platform || this._t('GEO_DB_UNKNOWN', 'unknown')),
                            this._esc(this._formatRecentDate(v.day, v.time)),
                        ])
                    )}
                </div>
            </div>
        `;
        this._bindNavLinks(body);
    }

    /**
     * User Detail: KPI + trend chart + Top pages visited, filtered
     * server-side to this one user/IP, plus the raw recent views list.
     * Mirrors the classic-admin 1.7 user-details.html.twig widget set.
     */
    async _loadUserDetail() {
        const body = this._detailBodyEl();
        if (!body) return;
        body.innerHTML = `<div class="state">${this._esc(this._t('ADMIN2.LOADING', 'Loading…'))}</div>`;

        const identity = this.#viewParams.user ? { user: this.#viewParams.user } : { ip: this.#viewParams.ip };
        const params = { ...this._dateRangeParams(), ...this._botFilterParams(), ...identity, limit: 100 };
        const [detailResult, summaryResult] = await Promise.allSettled([
            this._apiGet('/page-insights/users/detail', params),
            this._apiGet('/page-insights/summary', params),
        ]);

        if (detailResult.status === 'rejected') {
            body.innerHTML = `<div class="state error">${this._esc(detailResult.reason?.message || this._t('ADMIN2.ERROR_LOAD_USER_DETAIL', 'Could not load user detail'))}</div>`;
            return;
        }

        const d = detailResult.value;
        const summary = summaryResult.status === 'fulfilled' ? summaryResult.value : null;
        const { from, to } = this._currentDateRange();
        const hitsSeries = this._buildDailySeries(summary?.hits, from, to);

        body.innerHTML = `
            <div class="charts">
                ${this._chartCard(this._t('PAGE_VIEWS_WIDGET', 'Page views'), d.hits, hitsSeries, 'var(--primary)')}
            </div>
            <div class="grid">
                <div class="card wide">
                    <h3>${this._esc(this._t('TOP_PAGES', 'Top pages'))}</h3>
                    ${this._table(
                        [this._t('TABLE_PAGE', 'Page'), this._t('TABLE_HITS', 'Hits')],
                        (d.top_pages || []).map((p) => [this._pageCellHtml(p.route), p.hits])
                    )}
                </div>
                <div class="card wide">
                    <h3>${this._esc(this._tf('ADMIN2.RECENT_VIEWS_HEADING_USER', 'Recent views (%s hits)', d.hits))}</h3>
                    ${this._table(
                        [this._t('TABLE_PAGE', 'Page'), this._t('TABLE_BROWSER', 'Browser'), this._t('TABLE_PLATFORM', 'Platform'), this._t('TABLE_DATE', 'Date')],
                        (d.views || []).map((v) => [
                            this._pageCellHtml(v.route),
                            this._esc(v.browser || this._t('GEO_DB_UNKNOWN', 'unknown')),
                            this._esc(v.platform || this._t('GEO_DB_UNKNOWN', 'unknown')),
                            this._esc(this._formatRecentDate(v.day, v.time)),
                        ])
                    )}
                </div>
            </div>
        `;
        this._bindNavLinks(body);
    }

    _render() {
        if (this.#view === 'dashboard') {
            this._renderDashboardShell();
        } else {
            this._renderDetailShell();
        }
    }

    _renderDashboardShell() {
        this.shadowRoot.innerHTML = `
            <style>${this._styles()}</style>
            <div class="wrap">
                <div class="toolbar">
                    <div class="range">
                        <button data-range="7">${this._esc(this._t('ADMIN2.RANGE_7D', '7d'))}</button>
                        <button data-range="30">${this._esc(this._t('ADMIN2.RANGE_30D', '30d'))}</button>
                        <button data-range="90">${this._esc(this._t('ADMIN2.RANGE_90D', '90d'))}</button>
                        <button data-range="all">${this._esc(this._t('ADMIN2.RANGE_ALL_TIME', 'All time'))}</button>
                    </div>
                    <div class="toolbar-end">
                        <button class="hide-bots-btn ${this.#hideBots ? 'active' : ''}" title="${this._esc(this._t('ADMIN2.HIDE_BOTS_BUTTON_TITLE', 'Filter every KPI, chart and list on this dashboard to hits not recognized as bot traffic (based on the "Bot User Agents" list in the config tab) - best-effort, not a guarantee.'))}">${this._esc(this._t('ADMIN2.HIDE_BOTS_BUTTON', 'Hide bots'))}</button>
                        <span class="next-run"></span>
                        <span class="db-size" title="${this._esc(this._t('ADMIN2.DB_SIZE_TITLE', 'SQLite database file size'))}"></span>
                        <button class="db-maintain-btn" title="${this._esc(this._t('ADMIN2.DB_MAINTAIN_BUTTON_TITLE', 'Free up disk space or delete old statistics data'))}">${this._esc(this._t('ADMIN2.DB_MAINTAIN_BUTTON', 'Maintain database'))}</button>
                        <button class="refresh" title="${this._esc(this._t('ADMIN2.REFRESH', 'Refresh'))}">&#8635; ${this._esc(this._t('ADMIN2.REFRESH', 'Refresh'))}</button>
                    </div>
                </div>
                <div class="body"></div>

                <div class="lookup">
                    <div class="lookup-box">
                        <h3>${this._esc(this._t('ADMIN2.PAGE_LOOKUP', 'Page lookup'))}</h3>
                        <div class="lookup-row">
                            <input type="text" class="page-route" placeholder="/blog/some-article" />
                            <button class="page-search">${this._esc(this._t('ADMIN2.SEARCH', 'Search'))}</button>
                        </div>
                        <div class="page-result"></div>
                    </div>
                    <div class="lookup-box">
                        <h3>${this._esc(this._t('ADMIN2.USER_LOOKUP', 'User lookup'))}</h3>
                        <div class="lookup-row">
                            <input type="text" class="user-name" placeholder="username" />
                            <button class="user-search">${this._esc(this._t('ADMIN2.SEARCH', 'Search'))}</button>
                        </div>
                        <div class="user-result"></div>
                    </div>
                </div>
            </div>
        `;

        const root = this.shadowRoot;
        root.querySelectorAll('.range button').forEach((btn) => {
            btn.addEventListener('click', () => {
                this.#range = btn.dataset.range;
                this._highlightRange();
                this._load();
            });
        });
        root.querySelector('.refresh').addEventListener('click', () => this._load());
        root.querySelector('.hide-bots-btn').addEventListener('click', () => this._toggleHideBots());
        root.querySelector('.db-maintain-btn').addEventListener('click', () => this._openDbMaintainDialog());
        root.querySelector('.page-search').addEventListener('click', () => this._searchPage());
        root.querySelector('.user-search').addEventListener('click', () => this._searchUser());
        root.querySelector('.page-route').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this._searchPage();
        });
        root.querySelector('.user-name').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') this._searchUser();
        });

        this._highlightRange();
        this._highlightHideBots();
    }

    /**
     * Shell for Page Detail / User Detail: back-link + title, the same
     * range/refresh toolbar as the dashboard (state is shared via #range,
     * so the range picked on the dashboard carries over when you drill in),
     * and a .detail-body container filled by _loadPageDetail()/_loadUserDetail().
     */
    _renderDetailShell() {
        this.shadowRoot.innerHTML = `
            <style>${this._styles()}</style>
            <div class="wrap">
                <div class="detail-header">
                    <a href="${this._esc(location.pathname)}" class="back-link" data-nav="dashboard">&larr; ${this._esc(this._t('ADMIN2.BACK_TO_DASHBOARD', 'Back to dashboard'))}</a>
                    <h2>${this._esc(this._detailTitle())}</h2>
                </div>
                <div class="toolbar">
                    <div class="range">
                        <button data-range="7">${this._esc(this._t('ADMIN2.RANGE_7D', '7d'))}</button>
                        <button data-range="30">${this._esc(this._t('ADMIN2.RANGE_30D', '30d'))}</button>
                        <button data-range="90">${this._esc(this._t('ADMIN2.RANGE_90D', '90d'))}</button>
                        <button data-range="all">${this._esc(this._t('ADMIN2.RANGE_ALL_TIME', 'All time'))}</button>
                    </div>
                    <div class="toolbar-end">
                        <button class="hide-bots-btn ${this.#hideBots ? 'active' : ''}" title="${this._esc(this._t('ADMIN2.HIDE_BOTS_BUTTON_TITLE', 'Filter every KPI, chart and list on this dashboard to hits not recognized as bot traffic (based on the "Bot User Agents" list in the config tab) - best-effort, not a guarantee.'))}">${this._esc(this._t('ADMIN2.HIDE_BOTS_BUTTON', 'Hide bots'))}</button>
                        <button class="refresh" title="${this._esc(this._t('ADMIN2.REFRESH', 'Refresh'))}">&#8635; ${this._esc(this._t('ADMIN2.REFRESH', 'Refresh'))}</button>
                    </div>
                </div>
                <div class="detail-body"></div>
            </div>
        `;

        const root = this.shadowRoot;
        root.querySelectorAll('.range button').forEach((btn) => {
            btn.addEventListener('click', () => {
                this.#range = btn.dataset.range;
                this._highlightRange();
                this._load();
            });
        });
        root.querySelector('.refresh').addEventListener('click', () => this._load());
        root.querySelector('.hide-bots-btn').addEventListener('click', () => this._toggleHideBots());
        this._bindNavLinks(root);
        this._highlightRange();
        this._highlightHideBots();
    }

    _detailTitle() {
        if (this.#view === 'page-detail') {
            return this._tf('ADMIN2.PAGE_DETAIL_TITLE', 'Page detail: %s', this.#viewParams.route || '');
        }
        if (this.#view === 'user-detail') {
            if (this.#viewParams.user) return this._tf('ADMIN2.USER_DETAIL_TITLE', 'User detail: %s', this.#viewParams.user);
            if (this.#viewParams.ip) return this._tf('ADMIN2.USER_DETAIL_TITLE_ANONYMOUS', 'User detail: %s (anonymous)', this.#viewParams.ip);
        }
        return '';
    }

    _highlightRange() {
        this.shadowRoot.querySelectorAll('.range button').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.range === this.#range);
        });
    }

    /**
     * Flips the "Hide bots" toggle and reloads. Unlike _setRecentScope()
     * (which only re-fetches "Recently viewed pages"), this affects every
     * widget on the current view, so a full _load() - same as changing the
     * date range - is the correct amount of work here, not a narrower
     * partial reload.
     */
    _toggleHideBots() {
        this.#hideBots = !this.#hideBots;
        this.#hideBotsInitialized = true;
        this._highlightHideBots();
        this._load();
    }

    _highlightHideBots() {
        this.shadowRoot.querySelectorAll('.hide-bots-btn').forEach((btn) => {
            btn.classList.toggle('active', this.#hideBots);
        });
    }

    _renderBody() {
        const body = this.shadowRoot.querySelector('.body');
        if (!body) return;

        if (this.#loading) {
            body.innerHTML = `<div class="state">${this._esc(this._t('ADMIN2.LOADING', 'Loading…'))}</div>`;
            return;
        }

        if (!this.#overview) {
            body.innerHTML = `<div class="state error">${this._esc(this._error || this._t('ADMIN2.NO_DATA_AVAILABLE', 'No data available.'))}</div>`;
            return;
        }

        const o = this.#overview;
        const dbBadge = this.shadowRoot.querySelector('.db-size');
        if (dbBadge) dbBadge.textContent = o.db?.mb !== undefined ? this._tf('ADMIN2.DB_SIZE', 'Database size: %s MB', o.db.mb) : '';

        // Next scheduled run of the two optional automatic maintenance jobs
        // (see AutoSchedule/Stats::dbStats()) - null/absent when a job is
        // "disabled", same as the db-size badge this sits next to, both
        // fed by the same `overview()` `db` field so no extra request is
        // needed. Same root-level (not ADMIN2.*) translation keys as the
        // equivalent Classic Admin titlebar text (stats.html.twig).
        const nextRunEl = this.shadowRoot.querySelector('.next-run');
        if (nextRunEl) {
            const parts = [];
            if (o.db?.next_geo_db_update) {
                parts.push(this._tf('NEXT_GEO_DB_UPDATE', 'Next geo-DB update: %s', new Date(o.db.next_geo_db_update * 1000).toLocaleString()));
            }
            if (o.db?.next_auto_prune) {
                parts.push(this._tf('NEXT_AUTO_PRUNE', 'Next automatic pruning: %s', new Date(o.db.next_auto_prune * 1000).toLocaleString()));
            }
            nextRunEl.textContent = parts.join(' · ');
        }

        const { from, to } = this._currentDateRange();
        const hitsSeries = this._buildDailySeries(this.#summary?.hits, from, to);
        const visitorsSeries = this._buildDailySeries(this.#summary?.visitors, from, to);
        const usersSeries = this._buildDailySeries(this.#summary?.users, from, to);

        body.innerHTML = `
            <div class="charts">
                ${this._chartCard(this._t('PAGE_VIEWS_WIDGET', 'Page views'), o.total_page_views, hitsSeries, 'var(--primary)')}
                ${this._chartCard(this._t('UNIQUE_VISITORS_WIDGET', 'Unique visitors'), o.total_unique_visitors, visitorsSeries, '#22d3ee')}
                ${this._chartCard(this._t('UNIQUE_USERS_WIDGET', 'Unique users'), o.total_unique_users, usersSeries, '#f59e0b')}
            </div>

            <div class="grid">
                <div class="status-row">
                    <div class="card">
                        <h3>${this._esc(this._t('TOP_PAGES', 'Top pages'))}</h3>
                        ${this._table(
                            [this._t('TABLE_PAGE', 'Page'), this._t('TABLE_HITS', 'Hits'), this._t('TABLE_UNIQUE_VISITORS', 'Visitors')],
                            (o.top_pages || []).map((p) => [
                                `<span title="${this._esc(p.route)}">${this._esc(p.page_title || p.route)}</span>`,
                                p.hits,
                                p.visitors,
                            ])
                        )}
                    </div>

                    <div class="card">
                        <h3>${this._esc(this._t('TOP_STATUS_CODES', 'HTTP status codes'))}</h3>
                        ${this._table(
                            [this._t('TABLE_STATUS', 'Status'), this._t('TABLE_HITS', 'Hits')],
                            (o.status_codes || []).map((s) => [
                                s.http_code === 'other' ? this._esc(this._t('STATUS_CODE_OTHER', 'Other')) : s.http_code,
                                `<span title="${s.share}%">${s.hits}</span>`,
                            ])
                        )}
                    </div>
                </div>

                <div class="card">
                    <h3>${this._esc(this._t('TOP_COUNTRIES', 'Top countries'))}</h3>
                    ${this._bars(o.top_countries, 'country')}
                    ${this._geoStatusHtml()}
                </div>

                <div class="card">
                    <h3>${this._esc(this._t('TOP_BROWSERS', 'Top browsers'))}</h3>
                    ${this._bars(o.top_browsers, 'browser')}
                </div>

                <div class="card">
                    <h3>${this._esc(this._t('TOP_PLATFORMS', 'Top platforms'))}</h3>
                    ${this._bars(o.top_platforms, 'platform')}
                </div>

                <div class="card">
                    <h3>${this._esc(this._t('TOP_USERS', 'Top users'))}</h3>
                    ${this._table(
                        [this._t('TABLE_USER', 'User'), this._t('TABLE_HITS', 'Hits')],
                        (o.top_users || []).map((u) => [
                            u.user ? this._userCellHtml({ user: u.user }) : this._esc(this._t('ADMIN2.ANONYMOUS', '(anonymous)')),
                            u.hits,
                        ])
                    )}
                </div>

                <div class="card wide">
                    <div class="recent-header">
                        <h3>${this._esc(this._t('RECENTLY_VIEWED_PAGES', 'Recently viewed pages'))}</h3>
                        <div class="scope-toggle" role="group" aria-label="${this._esc(this._t('ADMIN2.FILTER_PAGES_SHOWN', 'Filter pages shown'))}">
                            <button class="scope-btn ${this.#recentScope === 'all' ? 'active' : ''}" data-scope="all">${this._esc(this._t('DEFAULT_PAGES_SCOPE_ALL', 'All pages'))}</button>
                            <button class="scope-btn ${this.#recentScope === 'real' ? 'active' : ''}" data-scope="real" title="${this._esc(this._t('ADMIN2.REAL_PAGES_ONLY_HELP', 'Only pages that exist under user/pages - excludes assets, sitemap.xml, robots.txt, 404s etc.'))}">${this._esc(this._t('DEFAULT_PAGES_SCOPE_REAL', 'Real pages only'))}</button>
                        </div>
                    </div>
                    ${this._table(
                        [this._t('TABLE_PAGE', 'Page'), this._t('TABLE_USER', 'User'), this._t('TABLE_BROWSER', 'Browser'), this._t('TABLE_PLATFORM', 'Platform'), this._t('TABLE_DATE', 'Date')],
                        this.#recentPages.map((r) => [
                            this._pageCellHtml(r.route),
                            this._userCellHtml({ user: r.user, ip: r.ip }),
                            this._esc(r.browser || this._t('GEO_DB_UNKNOWN', 'unknown')),
                            this._esc(r.platform || this._t('GEO_DB_UNKNOWN', 'unknown')),
                            this._esc(this._formatRecentDate(r.day, r.time)),
                        ])
                    )}
                    ${this.#recentPages.length && this.#recentHasMore ? `<button class="load-more-recent">${this._esc(this._t('ADMIN2.LOAD_MORE', 'Load more'))}</button>` : ''}
                </div>
            </div>
        `;

        body.querySelector('.load-more-recent')?.addEventListener('click', () => this._loadMoreRecent());
        body.querySelectorAll('.scope-btn').forEach((btn) => {
            btn.addEventListener('click', () => this._setRecentScope(btn.dataset.scope));
        });
        body.querySelector('.geo-db-update-btn')?.addEventListener('click', () => this._updateGeoDb());
        this._bindNavLinks(body);
    }

    /**
     * Switches the "Recently viewed pages" scope toggle (all/real) and
     * reloads just that card - the rest of the dashboard (KPIs, charts,
     * top lists) is untouched, since only Recently viewed pages supports
     * this filter for now (see PageInsightsApiController::getScopeFilter()
     * doc comment - Top Pages is planned for after the first release).
     */
    async _setRecentScope(scope) {
        if (scope === this.#recentScope) return;
        this.#recentScope = scope;
        this.#recentScopeInitialized = true;
        await this._reloadRecent();
        this._renderBody();
    }

    /**
     * "Page" cell for the Recently viewed pages table: a small trend icon
     * linking to the (currently empty) Page Detail sub-view, the existing
     * "open in a new tab" icon linking to the real site page, then the
     * route text itself (unlinked, see _externalLinkIcon() doc comment for
     * why the text stays plain). Mirrors the classic-admin 1.7 "Recently
     * Viewed Pages" widget, which showed the same pair of icons per row.
     */
    _pageCellHtml(route) {
        const encoded = encodeURIComponent(route || '');
        return `<span class="recent-page-cell">
            <a href="${this._esc(route)}" target="_blank" rel="noopener noreferrer" class="recent-page-link" title="${this._esc(this._tf('ADMIN2.OPEN_IN_NEW_TAB', 'Open %s in a new tab', route))}">${this._externalLinkIcon()}</a>
            <a href="?view=page-detail&route=${this._esc(encoded)}" class="recent-page-link nav-link" data-nav="page-detail" data-nav-route="${this._esc(route)}" title="${this._esc(this._t('ADMIN2.VIEW_PAGE_DETAIL', 'View page detail'))}">${this._trendIcon()}</a>
            <span class="recent-page-route" title="${this._esc(route)}">${this._esc(route)}</span>
        </span>`;
    }

    /**
     * "User" cell shared by Recently viewed pages and Top users: a trend
     * icon linking to User Detail plus the label. Links by username when
     * available; falls back to linking by IP for anonymous-but-identifiable
     * visitors (see PageInsightsApiController::userDetail(), which accepts
     * either param). Pass neither (Top users' aggregated anonymous bucket)
     * to get a plain, unlinked "(anonymous)" label.
     */
    _userCellHtml({ user, ip } = {}) {
        const label = user || ip || this._t('ADMIN2.ANONYMOUS', '(anonymous)');
        if (!user && !ip) {
            return this._esc(label);
        }
        const param = user ? `user=${encodeURIComponent(user)}` : `ip=${encodeURIComponent(ip)}`;
        const navAttr = user ? `data-nav-user="${this._esc(user)}"` : `data-nav-ip="${this._esc(ip)}"`;
        return `<span class="recent-page-cell">
            <a href="?view=user-detail&${this._esc(param)}" class="recent-page-link nav-link" data-nav="user-detail" ${navAttr} title="${this._esc(this._t('ADMIN2.VIEW_USER_DETAIL', 'View user detail'))}">${this._trendIcon()}</a>
            <span class="recent-page-route">${this._esc(label)}</span>
        </span>`;
    }

    /**
     * Re-requests /page-insights/recent with a larger `limit` (10 -> 20 -> 30 ...)
     * and re-renders just the body. Deliberately not an offset/cursor-based
     * pagination: re-fetching the whole newest-first list with a bigger
     * limit avoids duplicate/missing rows if new hits arrive between clicks,
     * and needs no extra server-side state.
     */
    async _loadMoreRecent() {
        const nextLimit = this.#recentLimit + 10;
        try {
            const data = await this._apiGet('/page-insights/recent', {
                ...this._dateRangeParams(),
                ...this._botFilterParams(),
                ...(this.#recentScope === 'real' ? { scope: 'real' } : {}),
                limit: nextLimit,
            });
            this.#recentPages = data.pages || [];
            this.#recentLimit = nextLimit;
            this.#recentHasMore = this.#recentPages.length >= nextLimit;
        } catch (err) {
            window.__GRAV_TOAST?.error(err.message || this._t('ADMIN2.ERROR_LOAD_MORE_RECENT', 'Could not load more recently viewed pages'));
        }
        this._renderBody();
    }

    /**
     * Re-fetches "Recently viewed pages" from scratch at the base limit
     * (10) for the current #recentScope - used when the scope toggle is
     * switched, so "Load more"'s growing limit doesn't carry over between
     * an "All pages" and a "Real pages only" view. Unlike _loadMoreRecent()
     * this doesn't re-render itself; callers do that once they're done
     * (see _setRecentScope() and the first-load path in _loadDashboard()).
     */
    async _reloadRecent() {
        this.#recentLimit = 10;
        try {
            const data = await this._apiGet('/page-insights/recent', {
                ...this._dateRangeParams(),
                ...this._botFilterParams(),
                ...(this.#recentScope === 'real' ? { scope: 'real' } : {}),
                limit: this.#recentLimit,
            });
            this.#recentPages = data.pages || [];
            this.#recentHasMore = this.#recentPages.length >= this.#recentLimit;
        } catch (err) {
            window.__GRAV_TOAST?.error(err.message || this._t('ADMIN2.ERROR_LOAD_RECENT', 'Could not load recently viewed pages'));
        }
    }

    _chartCard(title, total, series, color) {
        const chart = series.length ? this._lineChart(series, color) : `<div class="state">${this._esc(this._t('ADMIN2.NO_DATA', 'No data.'))}</div>`;
        return `
            <div class="card chart-card">
                <div class="chart-head">
                    <h3>${this._esc(title)}</h3>
                    <span class="chart-total">${this._esc(String(total ?? '0'))}</span>
                </div>
                ${chart}
            </div>`;
    }

    /**
     * 'YYYY-MM-DD' -> a short, locale-aware day/month label for the trend
     * chart's x-axis (e.g. '21.08.' for 'de-DE', '8/21' for 'en-US') -
     * replaces the previous fixed 'DD.MM.' format, which rendered the same
     * regardless of the admin's configured language (see
     * docs/ADMIN-UI.md, "Admin2 i18n" - this was the one still-open
     * item there). Uses the browser-native Intl.DateTimeFormat with the
     * same locale window.__GRAV_I18N already reports (see _t()'s doc
     * comment) - no new dependency, Intl has been available in every
     * browser Admin2 itself supports for years. Falls back to the
     * previous fixed 'DD.MM.' rendering if the bridge/locale is
     * unavailable or Intl throws for any reason (e.g. an unrecognized
     * locale string) - same fail-safe spirit as _t()'s own fallback: a
     * missing optional capability should degrade the chart, not break it.
     */
    _formatDayLabel(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        if (!m) return iso || '';

        const locale = window.__GRAV_I18N?.locale;
        if (locale) {
            try {
                // Local-time midnight, not UTC: `new Date(iso)` parses a bare
                // 'YYYY-MM-DD' as UTC midnight, which a negative-UTC-offset
                // browser timezone would then display as the *previous* day.
                const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
                return new Intl.DateTimeFormat(locale, { day: '2-digit', month: '2-digit' }).format(date);
            } catch (e) {
                // fall through to the fixed format below
            }
        }

        return `${m[3]}.${m[2]}.`;
    }

    /**
     * Formats a "recently viewed"-style table row's raw 'YYYY-MM-DD' day +
     * 'HH:MM:SS' time (as returned by every /recent, /pages/detail,
     * /users/detail response - see Stats::recentPages()) into one
     * locale-aware string, e.g. '21.08.2026 14:23:10' for 'de-DE',
     * '8/21/2026 14:23:10' for 'en-US'. Every such table (dashboard
     * "Recently viewed pages", Page/User Detail's own recent-views table,
     * the Page/User search results) used to render the date half of this
     * completely unformatted - a bare 'YYYY-MM-DD' string straight from
     * the API response, not even the old fixed 'DD.MM.' format
     * _formatDayLabel() had - found while localizing that chart-axis
     * format above. Time-of-day is left exactly as returned: a plain 24h
     * 'HH:MM:SS' reads the same regardless of locale, so it doesn't need
     * Intl involvement. Includes the year, unlike _formatDayLabel()'s
     * chart-axis format - these tables can span far more than one
     * currently-selected date range (e.g. the unbounded "Load more" list,
     * or a user/page's entire history on the Detail views), so day+month
     * alone would be ambiguous here in a way it isn't for a single chart.
     * Same locale/fallback/local-midnight reasoning as _formatDayLabel().
     */
    _formatRecentDate(day, time) {
        const raw = `${day || ''} ${time || ''}`.trim();
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day || '');
        if (!m) return raw;

        const locale = window.__GRAV_I18N?.locale;
        if (locale) {
            try {
                const date = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
                const formattedDay = new Intl.DateTimeFormat(locale, { year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
                return `${formattedDay} ${time || ''}`.trim();
            } catch (e) {
                // fall through to the raw rendering below
            }
        }

        return raw;
    }

    /**
     * A proper axis chart (y-axis gridlines/labels, x-axis date labels,
     * hover tooltips via native SVG <title> per point) rather than a bare
     * sparkline - closer to what the classic-admin version of this plugin
     * showed (three full charts with axes), while still fitting a
     * dashboard card instead of a whole separate admin page.
     */
    _lineChart(series, color) {
        const width = 480;
        const height = 170;
        const padLeft = 34;
        const padRight = 8;
        const padTop = 10;
        const padBottom = 20;
        const plotW = width - padLeft - padRight;
        const plotH = height - padTop - padBottom;

        const max = Math.max(...series.map((p) => p.value), 1);
        const yTickCount = 4;
        const yTicks = Array.from({ length: yTickCount + 1 }, (_, i) => Math.round((max / yTickCount) * i));

        const stepX = series.length > 1 ? plotW / (series.length - 1) : plotW;
        const points = series.map((p, i) => ({
            ...p,
            x: padLeft + i * stepX,
            y: padTop + plotH - (p.value / max) * plotH,
        }));

        const linePath = `M${points.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' L')}`;
        const baseline = (padTop + plotH).toFixed(1);
        const areaPath = `${linePath} L${points[points.length - 1].x.toFixed(1)},${baseline} L${points[0].x.toFixed(1)},${baseline} Z`;

        const gridlines = yTicks
            .map((v) => {
                const y = padTop + plotH - (v / max) * plotH;
                return `
                    <line class="grid-line" x1="${padLeft}" y1="${y.toFixed(1)}" x2="${width - padRight}" y2="${y.toFixed(1)}"></line>
                    <text class="axis-label y-label" x="${padLeft - 6}" y="${(y + 3).toFixed(1)}" text-anchor="end">${v}</text>`;
            })
            .join('');

        // A handful of evenly spaced x-axis labels rather than one per day -
        // that many labels overlap on anything but a 7-day range.
        const labelCount = Math.min(6, points.length);
        const labelStep = points.length > 1 ? (points.length - 1) / Math.max(1, labelCount - 1) : 0;
        const seenX = new Set();
        const xAxisLabels = Array.from({ length: labelCount }, (_, i) => points[Math.round(i * labelStep)])
            .filter((p) => {
                if (seenX.has(p.x)) return false;
                seenX.add(p.x);
                return true;
            })
            .map((p) => {
                // Middle labels can grow symmetrically; the first/last one
                // would grow past the viewBox edge with text-anchor="middle"
                // and get clipped (seen with the rightmost date, e.g.
                // "24.07." showing as "24.0"), so they anchor toward the
                // inside instead.
                const isFirst = p === points[0];
                const isLast = p === points[points.length - 1];
                const anchor = isLast ? 'end' : isFirst ? 'start' : 'middle';
                return `<text class="axis-label x-label" x="${p.x.toFixed(1)}" y="${height - 4}" text-anchor="${anchor}">${this._esc(this._formatDayLabel(p.date))}</text>`;
            })
            .join('');

        const dots = points
            .map(
                (p) =>
                    `<circle class="chart-dot" cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="2.5"><title>${this._esc(this._formatDayLabel(p.date))}: ${p.value}</title></circle>`
            )
            .join('');

        return `
            <svg class="line-chart" viewBox="0 0 ${width} ${height}" style="color:${color}">
                ${gridlines}
                <path class="chart-area" d="${areaPath}"></path>
                <path class="chart-line" d="${linePath}"></path>
                ${dots}
                ${xAxisLabels}
            </svg>`;
    }

    /**
     * Turns the raw rows from Stats::siteSummary() (one row per day *that
     * has data*, in no guaranteed order - see classes/Stats.php) into a
     * chronologically sorted array of {date, value}. When we know the
     * selected range (from/to), missing days are filled in with 0 so the
     * sparkline has an evenly spaced timeline instead of gaps wherever a
     * day had zero visits. For 'all time' (from/to unknown) we just sort
     * whatever days came back, without filling - the range could span
     * years and the exact start date isn't known client-side.
     */
    _buildDailySeries(rows, from, to) {
        const byDate = new Map();
        (rows || []).forEach((r) => {
            if (r && r.date) byDate.set(r.date, Number(r.hits) || 0);
        });

        if (!from || !to) {
            return [...byDate.entries()]
                .sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0))
                .map(([date, value]) => ({ date, value }));
        }

        const series = [];
        const cursor = new Date(from);
        cursor.setHours(0, 0, 0, 0);
        const end = new Date(to);
        end.setHours(0, 0, 0, 0);
        while (cursor <= end) {
            const key = cursor.toISOString().slice(0, 10);
            series.push({ date: key, value: byDate.get(key) || 0 });
            cursor.setDate(cursor.getDate() + 1);
        }
        return series;
    }

    /**
     * Converts a 2-letter ISO country code (as stored by Geolocation /
     * classes/Stats.php: $geo->countryCode(), empty falls back to the
     * literal string "unknown") into a small flag image.
     *
     * Deliberately NOT using the Unicode "flag" emoji (combined regional
     * indicator symbols) here: whether that renders as an actual flag
     * depends entirely on the OS/browser having a matching color-emoji
     * font installed, and on several common desktop Linux setups it just
     * shows as two plain letters or a blank box. An <img> renders
     * consistently everywhere. flagcdn.com is the same kind of external,
     * free flag source the classic-admin version of this plugin used
     * (flagpedia.net, per its README credits) - current CSP
     * (img-src 'self' https:, both public and /admin blocks, see
     * grav-chat-2026-07-18-user-folder-exposure-csp-htaccess.md) already
     * allows this without further Apache changes.
     */
    _flagIcon(code) {
        if (typeof code === 'string' && /^[A-Za-z]{2}$/.test(code)) {
            const lower = code.toLowerCase();
            return `<img class="bar-flag" src="https://flagcdn.com/${lower}.svg" alt="${this._esc(code.toUpperCase())}" loading="lazy" width="18" height="13">`;
        }
        return `<span class="bar-flag bar-flag-unknown" title="${this._esc(this._t('GEO_DB_UNKNOWN', 'unknown'))}">${this._globeIcon()}</span>`;
    }

    _globeIcon() {
        return `<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
            <circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.2"></circle>
            <ellipse cx="8" cy="8" rx="3" ry="7" fill="none" stroke="currentColor" stroke-width="1.2"></ellipse>
            <line x1="1" y1="8" x2="15" y2="8" stroke="currentColor" stroke-width="1.2"></line>
        </svg>`;
    }

    /**
     * Small "open in new tab" glyph used in front of a route in the
     * "Recently viewed pages" table. Deliberately only this icon is
     * wrapped in the <a>, not the route text itself - a full-text link
     * would pick up the browser's default link color/underline, which
     * looks out of place next to plain-text table cells (route text stays
     * themed via .recent-page-route, see _styles()).
     */
    _externalLinkIcon() {
        return `<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true">
            <path d="M6.5 3H3.5A1.5 1.5 0 0 0 2 4.5v8A1.5 1.5 0 0 0 3.5 14h8a1.5 1.5 0 0 0 1.5-1.5V9.5" fill="none" stroke="currentColor" stroke-width="1.3"></path>
            <path d="M9.5 2H14v4.5M14 2 7 9" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>`;
    }

    /**
     * Small "trending up" glyph used as the Page/User Detail link icon in
     * "Recently viewed pages" and "Top users" - the same role the small
     * chart icon played next to each row in the classic-admin 1.7 widget.
     */
    _trendIcon() {
        return `<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true">
            <path d="M2 12 6 7 9 9.5 14 3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"></path>
            <path d="M10.5 3H14v3.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>`;
    }

    /**
     * Status line + "Update now" button for the self-built geo country
     * index, rendered inside the "Top countries" card (see _renderBody()).
     * Deliberately lives here rather than in the config form: it's an
     * action tied to this stat, not a setting (see CountryIndexBuilder /
     * docs/GEOLOCATION.md for why there's no automatic
     * download - this button and its classic-admin equivalent are the only
     * ways the index ever gets (re)built).
     */
    _geoStatusHtml() {
        const s = this.#geoStatus;
        const busy = this.#geoBusy;

        let statusText;
        if (s === null) {
            statusText = this._t('ADMIN2.GEO_STATUS_UNAVAILABLE', 'Status unavailable.');
        } else if (!s.built) {
            statusText = this._t('GEO_DB_NOT_BUILT', 'Not built yet - country lookups return "unknown" until the first update.');
        } else {
            const builtAt = s.built_at ? new Date(s.built_at * 1000).toLocaleString() : this._t('ADMIN2.UNKNOWN_TIME', 'unknown time');
            const sourceDate = s.source_date || this._t('GEO_DB_UNKNOWN', 'unknown');
            const entries = (s.ipv4_entries || 0) + (s.ipv6_entries || 0);
            statusText = this._tf('GEO_DB_BUILT_STATUS', 'Built %s (source date %s, %s entries).', builtAt, sourceDate, entries.toLocaleString());
        }

        return `
            <div class="geo-db-status">
                <span class="geo-db-status-text">${this._esc(statusText)}</span>
                <button class="geo-db-update-btn" ${busy ? 'disabled' : ''}>${this._esc(busy ? this._t('ADMIN2.UPDATING', 'Updating…') : this._t('GEO_DB_UPDATE_NOW', 'Update now'))}</button>
            </div>
            ${this.#geoError ? `<div class="geo-db-error">${this._esc(this.#geoError)}</div>` : ''}
        `;
    }

    _bars(items, key) {
        if (!items || !items.length) return `<div class="state">${this._esc(this._t('ADMIN2.NO_DATA', 'No data.'))}</div>`;
        const max = Math.max(...items.map((i) => Number(i.hits) || 0), 1);
        return `<div class="bars">${items
            .map((i) => {
                const pct = Math.max(4, Math.round(((Number(i.hits) || 0) / max) * 100));
                const flag = key === 'country' ? this._flagIcon(i[key]) : '';
                return `
                    <div class="bar-row">
                        <span class="bar-label">${flag}${this._esc(String(i[key] || this._t('GEO_DB_UNKNOWN', 'unknown')))}</span>
                        <div class="bar-track"><div class="bar-fill" style="width:${pct}%"></div></div>
                        <span class="bar-value">${this._esc(String(i.hits))}${i.share !== undefined ? ` (${i.share}%)` : ''}</span>
                    </div>`;
            })
            .join('')}</div>`;
    }

    _table(headers, rows) {
        if (!rows.length) return `<div class="state">${this._esc(this._t('ADMIN2.NO_DATA', 'No data.'))}</div>`;
        return `
            <table>
                <thead><tr>${headers.map((h) => `<th>${this._esc(h)}</th>`).join('')}</tr></thead>
                <tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody>
            </table>`;
    }

    async _searchPage() {
        const route = this.shadowRoot.querySelector('.page-route').value.trim();
        const resultEl = this.shadowRoot.querySelector('.page-result');
        if (!route) return;
        resultEl.innerHTML = `<div class="state">${this._esc(this._t('ADMIN2.SEARCHING', 'Searching…'))}</div>`;
        try {
            const data = await this._apiGet('/page-insights/pages/detail', { ...this._botFilterParams(), route, limit: 50 });
            resultEl.innerHTML = `
                <p>${this._esc(this._tf('ADMIN2.HITS_VISITORS_SUMMARY', '%s hits, %s unique visitors', data.hits, data.visitors))}</p>
                ${this._table(
                    [this._t('TABLE_USER', 'User'), this._t('TABLE_DATE', 'Date'), this._t('TABLE_BROWSER', 'Browser')],
                    (data.views || []).map((v) => [
                        this._userCellHtml({ user: v.user, ip: v.ip }),
                        this._esc(this._formatRecentDate(v.day, v.time)),
                        this._esc(v.browser || ''),
                    ])
                )}`;
            this._bindNavLinks(resultEl);
        } catch (err) {
            resultEl.innerHTML = `<div class="state error">${this._esc(err.message)}</div>`;
        }
    }

    async _searchUser() {
        const user = this.shadowRoot.querySelector('.user-name').value.trim();
        const resultEl = this.shadowRoot.querySelector('.user-result');
        if (!user) return;
        resultEl.innerHTML = `<div class="state">${this._esc(this._t('ADMIN2.SEARCHING', 'Searching…'))}</div>`;
        try {
            const data = await this._apiGet('/page-insights/users/detail', { ...this._botFilterParams(), user, limit: 50 });
            resultEl.innerHTML = `
                <p>${this._esc(this._tf('ADMIN2.HITS_SUMMARY', '%s hits', data.hits))}</p>
                ${this._table(
                    // Reuses TABLE_PAGE ("Page") rather than a separate "Route" key - same
                    // meaning, and every other table in this file already uses TABLE_PAGE
                    // for this column (see _renderBody()/_loadPageDetail()).
                    [this._t('TABLE_PAGE', 'Page'), this._t('TABLE_DATE', 'Date')],
                    (data.views || []).map((v) => [this._pageCellHtml(v.route), this._esc(this._formatRecentDate(v.day, v.time))])
                )}`;
            this._bindNavLinks(resultEl);
        } catch (err) {
            resultEl.innerHTML = `<div class="state error">${this._esc(err.message)}</div>`;
        }
    }

    _esc(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        // textContent -> innerHTML only escapes &, < and >. The result is
        // interpolated into HTML *attributes* here (title=, href=,
        // data-nav-user=), not just text nodes, so " and ' must be escaped
        // too - otherwise a route/value containing a quote can break out of
        // the attribute and inject arbitrary markup/event handlers.
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    _styles() {
        return `
            :host { display: block; color: var(--foreground); font-family: inherit; padding-top: 16px; }
            .wrap { display: flex; flex-direction: column; gap: 16px; }
            .body, .detail-body { display: flex; flex-direction: column; gap: 16px; }
            .toolbar { display: flex; justify-content: space-between; align-items: center; }
            .range { display: flex; gap: 4px; }
            .range button, .refresh, .db-maintain-btn, .hide-bots-btn, .lookup-row button, .load-more-recent {
                background: var(--background);
                color: var(--foreground);
                border: 1px solid var(--border);
                border-radius: 6px;
                padding: 6px 12px;
                cursor: pointer;
                font-size: 13px;
            }
            .range button.active, .hide-bots-btn.active { background: var(--primary); color: var(--primary-foreground, #fff); border-color: var(--primary); }
            .db-maintain-btn:disabled { cursor: default; opacity: 0.6; }
            .toolbar-end { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; row-gap: 6px; }
            .db-size { font-size: 12px; color: var(--muted-foreground); white-space: nowrap; }
            .next-run { font-size: 12px; color: var(--muted-foreground); }
            .charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
            .chart-card { display: flex; flex-direction: column; }
            .chart-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
            .chart-head h3 { margin: 0; }
            .chart-total { font-size: 15px; font-weight: 700; }
            .line-chart { display: block; width: 100%; height: auto; }
            .grid-line { stroke: var(--border); stroke-width: 1; }
            .axis-label { font-size: 9px; fill: var(--muted-foreground); }
            .chart-area { fill: currentColor; opacity: 0.15; stroke: none; }
            .chart-line { fill: none; stroke: currentColor; stroke-width: 1.75; }
            .chart-dot { fill: currentColor; }
            .sparkline { width: 100%; height: 36px; margin-top: 10px; }
            .spark-area { fill: var(--primary); opacity: 0.12; }
            .spark-line { fill: none; stroke: var(--primary); stroke-width: 1.5; }
            .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; }
            .card { border: 1px solid var(--border); border-radius: 8px; padding: 14px; }
            .card.wide { grid-column: 1 / -1; }
            .card h3 { margin: 0 0 10px; font-size: 14px; }
            /* Top pages + HTTP status codes: paired 3/4 + 1/4 row, own nested
               grid so it can sit at that ratio without disturbing the equal-
               fraction auto-fit layout the other cards share. */
            .status-row { grid-column: 1 / -1; display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 1fr); gap: 12px; }
            @media (max-width: 700px) { .status-row { grid-template-columns: 1fr; } }
            table { width: 100%; border-collapse: collapse; font-size: 13px; }
            th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--border); }
            th { color: var(--muted-foreground); font-weight: 600; }
            .bars { display: flex; flex-direction: column; gap: 8px; }
            .bar-row { display: grid; grid-template-columns: 90px 1fr 70px; align-items: center; gap: 8px; font-size: 13px; }
            .bar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .bar-flag { margin-right: 4px; }
            .bar-track { background: var(--border); border-radius: 4px; height: 8px; overflow: hidden; }
            .bar-fill { background: var(--primary); height: 100%; }
            .bar-value { text-align: right; color: var(--muted-foreground); }
            .recent-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
            .recent-header h3 { margin: 0; }
            .scope-toggle { display: flex; gap: 4px; }
            .scope-btn {
                background: var(--background);
                color: var(--foreground);
                border: 1px solid var(--border);
                border-radius: 6px;
                padding: 4px 10px;
                cursor: pointer;
                font-size: 12px;
            }
            .scope-btn.active { background: var(--primary); color: var(--primary-foreground, #fff); border-color: var(--primary); }
            .recent-page-cell { display: inline-flex; align-items: center; gap: 6px; }
            .recent-page-link { color: var(--muted-foreground); display: inline-flex; text-decoration: none; }
            .recent-page-link:hover { color: var(--foreground); }
            .recent-page-route { color: var(--foreground); }
            .load-more-recent { display: block; margin-top: 12px; }
            .geo-db-status { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border); }
            .geo-db-status-text { color: var(--muted-foreground); font-size: 12px; }
            .geo-db-update-btn {
                background: var(--background);
                color: var(--foreground);
                border: 1px solid var(--border);
                border-radius: 6px;
                padding: 4px 10px;
                cursor: pointer;
                font-size: 12px;
                white-space: nowrap;
            }
            .geo-db-update-btn:disabled { cursor: default; opacity: 0.6; }
            .geo-db-error { color: var(--destructive, #dc2626); font-size: 12px; margin-top: 6px; }
            .detail-header { display: flex; flex-direction: column; gap: 6px; margin-bottom: 4px; }
            .back-link { color: var(--muted-foreground); text-decoration: none; font-size: 13px; align-self: flex-start; }
            .back-link:hover { color: var(--foreground); }
            .detail-header h2 { margin: 0; font-size: 16px; font-weight: 600; word-break: break-all; }
            .state { color: var(--muted-foreground); font-size: 13px; padding: 8px 0; }
            .state.error { color: var(--destructive, #dc2626); }
            .lookup { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 12px; }
            .lookup-box { border: 1px solid var(--border); border-radius: 8px; padding: 14px; }
            .lookup-box h3 { margin: 0 0 10px; font-size: 14px; }
            .lookup-row { display: flex; gap: 8px; margin-bottom: 10px; }
            .lookup-row input {
                flex: 1;
                background: var(--background);
                color: var(--foreground);
                border: 1px solid var(--border);
                border-radius: 6px;
                padding: 6px 8px;
                font-size: 13px;
            }
        `;
    }
}

customElements.define(TAG, PageInsightsPage);
