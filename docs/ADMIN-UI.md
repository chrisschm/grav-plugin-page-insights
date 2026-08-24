# Admin UI

This document describes the two admin surfaces' UI-level mechanics: Admin2's client-side routing,
its bridge into Grav's translation system, localized date formatting on both sides, and the
dashboard-wide "Hide bots" filter. It does **not** cover the underlying data model or query
mechanism (see [`DATABASES.md`](DATABASES.md) and "Backend: generic query filter" in
[`ARCHITECTURE.md`](ARCHITECTURE.md)) or the database maintenance dialog and geo-index rebuild
triggers (see [`MAINTENANCE.md`](MAINTENANCE.md) and [`GEOLOCATION.md`](GEOLOCATION.md)).
*(Eine deutsche Kurzfassung findest du am Ende dieser Datei.)*

## Admin2 sub-routing: query parameters, not path segments

Admin2's SvelteKit client router only knows a single dynamic segment per plugin page
(`/plugin/[slug]`, no catch-all). A deeper, self-built path segment (e.g. `/plugin/page-insights/
page-detail`) would fail client-side navigation, even though the server (`admin2.php`) answers
every sub-route correctly with the SPA shell. **Solution:** Page Detail, User Detail, and (since
2026-08-24) Scan detection are separate view *states* of the same fixed route, addressed purely via
query string (`?view=page-detail&route=...`, `?view=user-detail&user=...`/`?ip=...`,
`?view=scan-patterns`), driven by plain `history.pushState()`/`popstate`. The isolated custom
element has no access to SvelteKit's `$app/navigation`, but the native browser mechanism is
sufficient, since SvelteKit's own helpers do the same thing internally. Verified live: hard reload
on all four URL shapes works, browser back/forward works, and the currently selected time range
survives switching between Page/User Detail (shared `#range` state).

Page Detail and User Detail are assembled entirely from existing dashboard building blocks
(`_chartCard()`, `_lineChart()`, `_bars()`, `_table()`) - no separate rendering code path to
maintain. Scan detection reuses the same `_renderDetailShell()` (back-link, title, `.detail-body`
container) but not the range/"Hide bots" toolbar - it has neither a date range nor a bot-traffic
concept, so `_renderDetailShell()` swaps in a plain refresh button for that one view instead (see
`isScanDetection` in `page-insights.js`).

## Admin2 i18n

Unlike Classic Admin's Twig templates (which resolve `'PLUGIN_PAGE_INSIGHTS.X'|t` automatically
against the active admin language), `admin-next/pages/page-insights.js` is a plain Web Component
with no built-in connection to Grav's translation system - until this was wired up, every UI string
in the Admin2 dashboard was a hardcoded English literal, regardless of the admin's configured
language (while the Classic Admin config form for the same plugin correctly rendered translated).
Confirmed there is no plugin-side workaround needed: `grav-admin-next` (the SvelteKit SPA behind
Admin2) itself installs a read-only global bridge for exactly this, `window.__GRAV_I18N`
(`src/lib/stores/i18n.svelte.ts`, doc comment: *"Global i18n bridge for plugin web-component bundles
... that aren't built against admin-next's Svelte runtime"*) - the same pattern already used here
for `window.__GRAV_TOAST`. Interface: `t(key, params?)`, `tHtml(key, params?)`, `has(key)`, `locale`,
`dir`, `subscribe(fn)`.

Two things worth knowing before touching this:

- **No `%s`/ICU substitution for plugin keys.** `t()`'s ICU `params` support only applies to keys
  registered under admin-next's own `ICU.*` namespace (its core UI strings, translated via
  translations.getgrav.org) - plugin keys arrive as plain strings sourced from this repo's
  `languages/*.yaml` via the `/translations` API endpoint and are returned verbatim. `page-insights.js`'s
  `_t()`/`_tf()` helpers wrap the bridge: `_t(key, fallback)` returns the translation or the given
  English fallback if the bridge/key is unavailable (checking `has()` explicitly, since an unknown
  key still humanizes into readable-ish text rather than returning `undefined`); `_tf()` additionally
  does client-side `%s` substitution, mirroring the sprintf-style positional args Classic Admin's
  Twig templates already get for free from Grav's `|t(a, b, ...)` filter (see `GEO_DB_BUILT_STATUS`
  in `themes/admin/templates/widgets/geo-db-status.html.twig` - same key, same placeholder order,
  reused as-is by the Admin2 side of the geo-db status line).
- **No reactivity.** Everything in this file is plain `innerHTML` template strings, not a reactive
  framework - a live admin language switch wouldn't otherwise be reflected until a full reload.
  `connectedCallback()` subscribes to `window.__GRAV_I18N.subscribe()` and re-runs `_render()` +
  `_load()` on a locale change (unsubscribed in `disconnectedCallback()`). This costs an extra API
  round-trip on every language switch (simplest correct behaviour across all three views - the
  dashboard alone could re-render cheaply from cached state, but the detail views don't keep their
  last response around) - an acceptable trade-off given how rarely a user changes admin language
  mid-session.

New Admin2-only strings (dashboard chrome with no Classic Admin equivalent - "Loading…", "No data.",
range-picker buttons, etc.) live under a new `PLUGIN_PAGE_INSIGHTS.ADMIN2.*` block in
`languages/{en,de,fr}.yaml`; everything with a direct Classic Admin equivalent (`TOP_COUNTRIES`,
`RECENTLY_VIEWED_PAGES`, the `GEO_DB_*` geo-status keys, etc.) reuses the existing top-level keys
rather than duplicating them.

**Short-code vs. BCP47 language files - see [`HISTORY.md`](HISTORY.md) #10 and #11.**
`/translations` (the API endpoint the bridge above actually calls) resolves plugin strings by the
*exact* admin locale code (`de-DE`), while this plugin's `languages/*.yaml` use the short-code
convention (`de`) - two buckets that Grav core never merges on its own.
`PageInsightsPlugin::mergeAdmin2TranslationAliases()` (hooked into `onPluginsInitialized()` - **not**
an `onApi*` event, see `HISTORY.md` #11 for why) bridges this at runtime; if `has()` ever returns
`false` for a key that's clearly present in `languages/de.yaml`, check there before assuming the key
itself is missing.

Chart x-axis date labels (`_formatDayLabel()`) are locale-aware too, via the same `locale` this
bridge reports - see "Localized date formatting" below.

## Localized date formatting

Both admin UIs render calendar dates in several places outside of `|t`-translated UI chrome, and
until 2026-08 every one of them was either a fixed, non-localized format or - for the two
chart-axis/table-date spots found only after live-testing the first round of fixes against real
Grav 1.7 and 2.0 test instances - not formatted *at all* (a bare `YYYY-MM-DD`/`HH:MM:SS` string
straight from `Stats`, e.g. `2026-07-25` as an x-axis label). Each side is fixed independently, in
whatever way is natural for that side, rather than through one shared mechanism - there's no PHP
involved in the Admin2 Web Component's rendering, and no JavaScript involved in Classic Admin's
server-rendered Twig output, so a single shared implementation would mean introducing a dependency
neither side actually needs. Five spots total: two per side, plus one more found later on the
Classic Admin side only (see below) - Admin2's equivalent displays were already correct from the
start, see that spot's own entry.

- **Admin2 trend-chart x-axis** (`_formatDayLabel()`) - was a fixed `DD.MM.` format regardless of
  admin language; the original still-open item. Now the browser-native `Intl.DateTimeFormat`, given
  the same `locale` `window.__GRAV_I18N` already reports (see "Admin2 i18n" above) - no new
  dependency, every browser Admin2 itself supports has had `Intl` for years. Falls back to the
  previous fixed `DD.MM.` rendering if the bridge/locale is unavailable or `Intl` throws (e.g. an
  unrecognized locale string), the same fail-safe spirit as `_t()`'s own fallback. Deliberately
  constructs a local-time `Date` from the parsed `YYYY-MM-DD` components rather than `new Date(iso)`
  - the latter parses a bare date-only ISO string as UTC midnight, which a negative-UTC-offset
  browser timezone would then render as the *previous* day once formatted back in local time.
- **Admin2 "recently viewed"-style tables** (`_formatRecentDate()`) - the dashboard's "Recently
  viewed pages" table, the Page/User Detail views' own recent-views table, and the Page/User search
  results: not previously formatted at all (a raw `YYYY-MM-DD HH:MM:SS`-ish concatenation, found
  live-testing the chart-axis fix above). Same `Intl.DateTimeFormat`/locale/fallback/local-midnight
  approach as `_formatDayLabel()`, but **includes the year**, unlike the chart axis - these tables
  can span far more than one currently-selected date range (an unbounded "Load more" list, or a
  page/user's entire history), so day+month alone would be ambiguous here in a way it isn't for a
  single chart. Time-of-day is left exactly as returned - a plain 24h `HH:MM:SS` reads the same
  regardless of locale.
- **Classic Admin "Recently viewed pages" day-group headers**
  (`day|page_insights_localized_day` Twig filter) - was the English-only `day|date('F jS')`
  regardless of admin language; not previously documented as a bug, found while fixing the Admin2
  chart-axis label above. Backed by `classes/LocalizedDate.php::longDay()`.
- **Classic Admin dashboard chart x-axes** (`s.date|page_insights_short_day` Twig filter, in
  `widgets/page-views.html.twig`/`unique-visitors.html.twig`/`unique-users.html.twig`) - like the
  Admin2 tables above, not previously formatted at all: `x: "{{ s.date }}"` fed a raw `YYYY-MM-DD`
  straight into Chart.js as an axis label, found the same way, live-testing on the actual Grav 1.7
  environment. Backed by `classes/LocalizedDate.php::shortDay()`, deliberately matching Admin2's
  `_formatDayLabel()` output byte-for-byte per locale (`21.08.` for `de`, `08/21` for `en`, `21/08`
  for `fr`) so both dashboards' charts look the same regardless of which admin UI is open - and,
  like that JS-side format, deliberately omits the year for the same reason (a single, currently-
  selected date range shown elsewhere on the page).
- **Classic Admin's three "next scheduled run"/"built at" status lines** (`next_geo_db_update`/
  `next_auto_prune` in `stats.html.twig`, `builtAt` in `widgets/geo-db-status.html.twig`) - found
  2026-08-22, live-testing the rollup feature against real Grav 1.7/2.0 instances (same discovery
  method as the other spots above): all three used Twig's `|date('Y-m-d H:i')`, the same
  non-localized formatting bug as the day-group headers above, just not previously caught because
  it wasn't part of that round's live-testing pass. Admin2's equivalent (`o.db.next_geo_db_update`
  etc. in `admin-next/pages/page-insights.js`) was already correct - it always used the browser's
  `toLocaleString()`, never a hardcoded format. Unlike `longDay()`/`shortDay()` above, these three
  are Unix timestamps with a time-of-day, not day-only ISO strings, so they're backed by a new
  `LocalizedDate::dateTime()` (`IntlDateFormatter::MEDIUM`/`SHORT`) and a new
  `page_insights_localized_datetime` Twig filter rather than reusing either existing method.
  Deliberately **not** touched: the structurally identical `date('Y-m-d H:i', $builtAt)` in
  `cli/GeoDbUpdateCommand.php` and the scheduler-job log line in
  `PageInsightsPlugin::registerGeoDbAutoUpdateJob()` - both write to a terminal/log file for the
  site operator, not a browser-rendered admin UI, where a technical, locale-independent timestamp
  is the more appropriate choice, not a bug.

`classes/LocalizedDate.php`'s three methods (`longDay()`, `shortDay()`, `dateTime()`) are all given
the current admin language via `$grav['language']->getLanguage()` - "active if set, else default",
the same resolution `Language::translate()` itself falls back to, so all three always track whatever
language the rest of the same page's `|t`-translated strings are already rendering in. Mapped
through the same short-code -> locale convention already established by
`mergeAdmin2TranslationAliases()` above (`de`/`en`/`fr`, this plugin's shipped languages), just
aimed at an ICU locale (`de_DE`) instead of a BCP47 code (`de-DE`). `shortDay()` uses a fixed custom
ICU pattern per locale rather than a standard length constant - no standard `IntlDateFormatter`
length (`LONG`/`MEDIUM`/`SHORT`) produces "day+month, no year" directly, they all include a year.
`dateTime()` uses `MEDIUM`/`SHORT` rather than `SHORT`/`SHORT` for the same reason `shortDay()`
avoids a bare `SHORT` date: ICU's `SHORT` date length renders a 2-digit year for `de` (`22.08.26`),
which a "next scheduled run" status line shouldn't risk being misread as.

`ext-intl` is deliberately **not** a new hard requirement (`composer.json` still only lists
`ext-sqlite3`/`ext-pdo`) - confirmed against Grav core itself
(`Pages::orderCollection()`/`PageCollection::order()`) that it treats `intl` the same way, guarding
every use behind `extension_loaded('intl')` rather than assuming it's present. `LocalizedDate`
mirrors that: when the extension is missing (or formatting fails for any other reason), each method
falls back to a neutral rendering (`longDay()`: `Y-m-d`; `shortDay()`: the previous fixed `d.m.`)
rather than silently keeping the old hardcoded English `F jS` - "wrong language" was the bug being
fixed here, so falling back to "no language"/"the old fixed format" rather than "always English
again" is the safer floor, same fail-safe principle already used for the geo country lookup (a
missing optional capability degrades gracefully, never breaks rendering or silently keeps showing
the wrong thing).

## "Hide bots" filter (`PageInsightsApiController::getBotFilter()`)

A toolbar toggle in the Admin2 dashboard (and Page/User Detail views) that filters every KPI, chart
and list to hits not recognized as bot traffic - added after two upstream Page Stats issues asked
for exactly this (`filter/recognise bots or crawlers`, `Iranian bots not filtered out?`). Admin2-
only, like `default_pages_scope` (see "Config blueprint" in `ARCHITECTURE.md`) - no Classic Admin
equivalent; this is a read-only display filter, not a mutating action, so per "Design goals" in
`ARCHITECTURE.md` it could go there too, but there's no live per-view toggle mechanism on that side
to hang it off of (Classic Admin's own "real pages" scope precedent never got one either).

**Data model:** no schema change. Backed entirely by the existing `data.is_bot` column, populated
since the very first migration by `Stats::collect()`/`Stats::isBot()` from the `bot_regexp` config
list, but never read back by any query until now - see `DATABASES.md` for the column's exact
history and caveats (in short: a best-effort, user-agent-substring classification, not a guarantee;
a bot that doesn't self-identify in its UA, or that spoofs a real browser's UA, is invisible to it).
`getBotFilter()` turns `?hide_bots=1` into the `Stats::query()` equality filter `['is_bot' => 0]` -
no new query method needed, the existing generic filter mechanism (see "Backend: generic query
filter" in `ARCHITECTURE.md`) already covers it.

**Scope - deliberately dashboard-wide, unlike `getScopeFilter()`:** the existing "real pages only"
scope filter only ever applies to "Recently viewed pages" (see its own doc comment) - a narrower,
single-card filter was the right call there, since it answers a different question ("which routes
count as real content"). "Hide bots" answers "how many of my visits are actually human", which only
makes sense applied consistently everywhere - `getBotFilter()`'s result is merged into every
endpoint in `PageInsightsApiController` (`overview()`'s KPI totals and every "top" list,
`pages()`/`countries()`/`browsers()`/`platforms()`/`users()`, `pageDetail()`/`userDetail()`,
`recent()`, `summary()`), and the Admin2 dashboard's toggle click handler calls the same full
`_load()` a date-range change would, not the narrower single-card reload `_setRecentScope()` uses
for the pages scope toggle.

**Admin-configurable default** (`default_hide_bots` config field, default `false`/off): same
first-load-adoption pattern as `default_pages_scope` (`PageInsightsApiController::
getDefaultHideBots()`, echoed in the `/overview` response) - defaulting to off so upgrading
installs' dashboard numbers don't silently change. Adopting a default of `true` is more expensive
here than for the pages scope, though: since the filter is dashboard-wide rather than one card, the
dashboard's very first `/overview` request (sent before any config default is known) can't yet
include `hide_bots=1`, so a site with the default turned on pays for a second, full dashboard
reload on first load once the true default arrives - accepted as a rare, self-selected cost (only
sites that opted into the non-default choice hit it), mirroring the same trade-off the pages-scope
default already makes structurally, just paid across the whole dashboard instead of one card.

---

## Auf Deutsch (Kurzfassung)

Diese Datei beschreibt die UI-Mechanik beider Admin-Oberflächen: Admin2-Client-Routing, die
i18n-Bridge, lokalisierte Datumsformatierung auf beiden Seiten und den dashboard-weiten
"Bots ausblenden"-Filter. Datenmodell und Abfragemechanismus stehen in `DATABASES.md`/
`ARCHITECTURE.md`, der DB-Wartungsdialog und der Geo-Index-Rebuild-Auslöser in `MAINTENANCE.md`/
`GEOLOCATION.md`.

**Admin2-Sub-Routing:** da SvelteKits Router nur ein einziges dynamisches Pfadsegment pro
Plugin-Seite kennt, werden Page-/User-Detail- sowie (seit 24.08.2026) die Scan-Erkennungs-Ansicht
als Query-String-Zustand derselben festen Route umgesetzt (`?view=page-detail&route=...`,
`?view=scan-patterns`), per `history.pushState()`/`popstate` - funktioniert
nachweislich mit Hard-Reload, Browser-Vor/Zurück und geteiltem Zeitraum-Zustand.

**Admin2-i18n:** `admin-next` stellt für genau diesen Fall (Plugin-Web-Components außerhalb des
Svelte-Runtimes) die globale Bridge `window.__GRAV_I18N` bereit (`t()`/`has()`/`locale`/
`subscribe()`). Zwei Fallstricke: keine ICU-`%s`-Substitution für Plugin-Keys (eigene `_tf()`-Hilfe
nötig), und keine Reaktivität (eigenes Neu-Rendern bei Sprachwechsel via `subscribe()` nötig). Kurz-
code- (`de`) vs. BCP47-Sprachdateien (`de-DE`) mussten zur Laufzeit zusammengeführt werden
(`mergeAdmin2TranslationAliases()`, siehe `HISTORY.md` #10/#11).

**Lokalisierte Datumsformatierung:** fünf Stellen insgesamt (zwei je Admin-Seite, eine weitere nur
Classic Admin) waren entweder fest englisch oder komplett unformatiert - jede Seite wurde
unabhängig gefixt (Admin2: `Intl.DateTimeFormat` browserseitig; Classic Admin: `LocalizedDate.php`
mit `IntlDateFormatter`, mit Fallback auf ein neutrales Format falls `ext-intl` fehlt). `ext-intl`
bleibt dabei bewusst optional, nicht zur Hard-Requirement erhoben.

**"Bots ausblenden"-Filter:** Admin2-exklusiv, dashboard-weit (nicht nur eine Karte wie der
"echte Seiten"-Scope-Filter), nutzt die bestehende `is_bot`-Spalte und den generischen
Filter-Mechanismus ohne neue Query-Methode. Standardmäßig aus, damit sich Dashboard-Zahlen
bestehender Installationen beim Update nicht stillschweigend ändern.
