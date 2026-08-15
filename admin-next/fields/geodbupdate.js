/**
 * Custom Admin Next blueprint field ("type: geodbupdate", see
 * blueprints.yaml, tab_general -> section_geolocation): shows the current
 * state of the self-built geo country index (classes/Geolocation/
 * CountryIndexBuilder.php) and lets an admin trigger a (re)build.
 *
 * Follows Grav's documented custom field contract (a plain, framework-free
 * custom element, auto-discovered from admin-next/fields/<type>.js - no PHP
 * registration needed): https://learn.getgrav.org/20/api/developer-guide
 * (see "Admin Next Custom Form Fields"). Reuses the same window.__GRAV_*
 * API conventions already used by admin-next/pages/page-insights.js
 * (_apiUrl/_apiHeaders there) rather than inventing a second convention.
 *
 * Deliberately NOT built at install time and NOT on any page-request path -
 * this only ever runs when an admin explicitly loads this settings tab
 * (GET .../geo-db/status, cheap/read-only) or clicks the button (POST
 * .../geo-db/rebuild, downloads+processes the RIR snapshot, can take a
 * while). See PageInsightsApiController::geoDbStatus()/rebuildGeoDb().
 */
(function () {
    const TAG = window.__GRAV_FIELD_TAG;

    function apiUrl(path) {
        const base = window.__GRAV_API_SERVER_URL || '';
        const prefix = window.__GRAV_API_PREFIX || '/api/v1';
        return `${base}${prefix}${path}`;
    }

    function apiHeaders(extra = {}) {
        const headers = { ...extra };
        const token = window.__GRAV_API_TOKEN;
        if (token) headers['X-API-Token'] = token;
        return headers;
    }

    async function apiGet(path) {
        const resp = await fetch(apiUrl(path), { headers: apiHeaders() });
        const body = await resp.json().catch(() => ({}));
        if (!resp.ok) {
            throw new Error(body.detail || body.title || `Request failed (${resp.status})`);
        }
        return body.data !== undefined ? body.data : body;
    }

    async function apiPost(path) {
        const resp = await fetch(apiUrl(path), {
            method: 'POST',
            headers: apiHeaders({ 'Content-Type': 'application/json' }),
            body: '{}',
        });
        const body = await resp.json().catch(() => ({}));
        if (!resp.ok) {
            throw new Error(body.detail || body.title || `Request failed (${resp.status})`);
        }
        return body.data !== undefined ? body.data : body;
    }

    function formatTimestamp(unixSeconds) {
        if (!unixSeconds) return null;
        try {
            return new Date(unixSeconds * 1000).toLocaleString();
        } catch (e) {
            return null;
        }
    }

    function formatSourceDate(yyyymmdd) {
        if (!yyyymmdd || yyyymmdd.length !== 8) return null;
        return `${yyyymmdd.slice(0, 4)}-${yyyymmdd.slice(4, 6)}-${yyyymmdd.slice(6, 8)}`;
    }

    class GeoDbUpdateField extends HTMLElement {
        _field = null;
        _status = null; // last known /geo-db/status or /geo-db/rebuild result
        _loading = false;
        _error = null;
        _loaded = false;

        set field(f) {
            this._field = f;
            this._render();
        }

        // Required by the field contract even though this field has no
        // meaningful stored form value of its own - the actual state lives
        // server-side in the index file, fetched via /geo-db/status.
        set value(v) {}
        get value() {
            return null;
        }

        connectedCallback() {
            this.attachShadow({ mode: 'open' });
            this._render();
            this._loadStatus();
        }

        async _loadStatus() {
            try {
                this._status = await apiGet('/page-insights/geo-db/status');
                this._error = null;
            } catch (e) {
                this._error = e.message;
            }
            this._loaded = true;
            this._render();
        }

        async _handleRebuildClick() {
            if (this._loading) return;
            this._loading = true;
            this._error = null;
            this._render();

            try {
                this._status = await apiPost('/page-insights/geo-db/rebuild');
            } catch (e) {
                this._error = e.message;
            }

            this._loading = false;
            this._render();
            this.dispatchEvent(new CustomEvent('change', { detail: null, bubbles: true }));
        }

        _statusSummary() {
            if (!this._loaded) {
                return 'Loading current status…';
            }
            if (!this._status || !this._status.built) {
                return 'No geo country index built yet. Country lookups will show as "unknown" until the first build.';
            }
            const built = formatTimestamp(this._status.built_at);
            const source = formatSourceDate(this._status.source_date);
            const parts = [];
            if (built) parts.push(`Built ${built}`);
            if (source) parts.push(`from the RIR snapshot dated ${source}`);
            const counts = `${(this._status.ipv4_entries ?? 0).toLocaleString()} IPv4 / ${(this._status.ipv6_entries ?? 0).toLocaleString()} IPv6 entries`;
            return `${parts.join(' ') || 'Built'} (${counts}).`;
        }

        _render() {
            if (!this.shadowRoot) return;
            const label = this._field?.label || 'Geo country index';
            const help = this._field?.help
                || 'Downloads the current RIR delegated-stats snapshot and rebuilds the self-hosted, country-only IP lookup used for "Top countries" - no third-party database is shipped with or downloaded by the plugin automatically.';

            this.shadowRoot.innerHTML = `
                <style>
                    :host { display: block; font-family: inherit; }
                    .label { font-weight: 600; margin-bottom: 4px; }
                    .help { font-size: 0.85em; opacity: 0.75; margin-bottom: 10px; }
                    .status { font-size: 0.9em; margin-bottom: 10px; }
                    .error { color: #c0392b; font-size: 0.9em; margin-bottom: 10px; }
                    button {
                        padding: 8px 16px;
                        cursor: pointer;
                        border: 1px solid #ccc;
                        border-radius: 4px;
                        background: #f5f5f5;
                    }
                    button:disabled { opacity: 0.6; cursor: not-allowed; }
                </style>
                <div class="label">${label}</div>
                <div class="help">${help}</div>
                <div class="status">${this._statusSummary()}</div>
                ${this._error ? `<div class="error">${this._error}</div>` : ''}
                <button ${this._loading ? 'disabled' : ''}>
                    ${this._loading ? 'Updating… this can take a moment' : 'Update geo database now'}
                </button>
            `;

            this.shadowRoot.querySelector('button')
                .addEventListener('click', () => this._handleRebuildClick());
        }
    }

    customElements.define(TAG, GeoDbUpdateField);
})();
