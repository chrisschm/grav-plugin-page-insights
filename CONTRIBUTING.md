# Contributing to Page Insights

Thank you for considering a contribution! *(Eine deutsche Kurzfassung findest du am Ende dieser Datei.)*

## This GitHub repository is a read-only mirror

Development happens on **Codeberg**. This GitHub repository is automatically mirrored from there
and is **read-only** — issues and pull requests opened here will not be reviewed and will be
closed/redirected.

- Main repository: https://codeberg.org/chschmidt/grav-plugin-page-insights
- Report a bug or request a feature: https://codeberg.org/chschmidt/grav-plugin-page-insights/issues/new/choose
- Submit code changes: open a pull request against Codeberg

## Design goals (please keep these in mind for any change)

- Must stay installable via GPM without any manual steps by the end user. The plugin has one real
  runtime dependency (`ip2location/ip2location-php`), but `vendor/` is committed to the repository
  precisely so that end users installing via GPM or Admin never need Composer themselves. Any
  change to dependencies must keep this working — see the note on the autoloader below.
- Must keep working on **both** supported Admin UIs: Classic Admin (Grav 1.x, templates under
  `themes/admin/templates/`) and Admin2 (Grav 2.0, `admin-next/pages/page-insights.js`). A change
  that only considers one of the two is incomplete.
- SQL access goes exclusively through the generic filter mechanism in `classes/Stats.php`
  (`Stats::query()` and the `$params`-based methods). Before adding a new, narrowly-filtered query
  method, check whether the existing mechanism already covers it.

If a change would require adding a new Composer dependency or would affect either Admin UI's
compatibility, please open an issue first to discuss it before investing time in a PR.

## Before opening a pull request

1. **Target branch:** please branch from and target `main`. (`develop` is used internally for
   staging larger changes and isn't part of the external contribution workflow — you don't need
   to worry about it.) The separate `translate` branch is managed by Codeberg Translate/Weblate
   and is likewise not part of the regular contribution workflow (see Translations below).
2. **PHP version:** the plugin requires PHP >= 8.0 (see `composer.json`). Please avoid syntax or
   functions that require a newer PHP version unless you also raise the requirement in
   `composer.json` *and* discuss it in an issue first — this affects every user on shared/older
   hosting.
3. **Changed the `classes/` namespace, file layout, or `composer.json`'s `autoload`/`require`
   sections?** Run `composer dump-autoload` locally and commit the regenerated
   `vendor/composer/autoload_*.php`, `vendor/composer/ClassLoader.php`, `vendor/composer/
   platform_check.php`, and `vendor/autoload.php`. These are compiled files, not derived
   automatically from `composer.json` by a plain `git clone` — a mismatch here causes a hard
   `Class "..." not found` fatal error for anyone installing from a fresh checkout, even though
   `composer.json` itself is correct.
4. **Syntax check:** PRs targeting `main` automatically run a syntax-only CI check (PHP, JS, Twig)
   via `.forgejo/workflows/lint.yml` — but please still check your own changes locally before
   opening the PR rather than relying on CI to catch it, especially since CI may not run at all
   for PRs from outside contributors depending on Codeberg's Actions permissions for forks:
   ```bash
   php -l path/to/changed-file.php
   ```
   For changes to `admin-next/pages/page-insights.js`: `node --check admin-next/pages/page-insights.js`
5. **Manual testing:** there is no automated test suite yet. Please briefly describe in the PR
   description how you tested your change (Grav version, PHP version, and whether you tested
   Classic Admin, Admin2, or both, as relevant to the change).
6. Keep changes focused — smaller, single-purpose PRs are much easier to review than large ones.

## Translations

Admin panel translations are managed via [Codeberg Translate](https://translate.codeberg.org/engage/grav-plugin-page-insights/)
(a hosted Weblate instance), **not** through regular pull requests. If you'd like to add or
improve a translation, please use that web interface instead of editing `languages/*.yaml`
directly.

- Weblate pushes translation changes to a dedicated `translate` branch, not `main`. Maintainers
  periodically bring finished/sufficiently complete language files over to `main` by hand — as a
  translator you don't need to open a pull request or worry about branches yourself.
- The source/base language is `languages/en.yaml`. If you're adding a *new* translatable string
  (not just translating an existing one), it needs to be added there first as part of a regular
  code PR — only then does it show up in Weblate for translators to pick up.
- The top-level `name`/`description` fields in `blueprints.yaml` are intentionally **not** part
  of this translation setup and stay as plain English text, matching how the plugin is listed in
  GPM/Packagist.

[![Translation status](https://translate.codeberg.org/widget/grav-plugin-page-insights/svg-badge.svg)](https://translate.codeberg.org/engage/grav-plugin-page-insights/)

## Configuration & code overview

- `page-insights.php` — plugin events, dashboard/detail-view registration, DB bootstrapping
- `classes/Stats.php` — the data layer (PDO/SQLite), including the generic `$params` query filter
  mechanism used throughout
- `classes/Api/PageInsightsApiController.php` — REST controller backing Admin2
  (`/page-insights/...` endpoints)
- `classes/Geolocation/` — IP2Location lookups and IP handling/anonymization
- `blueprints.yaml` — Admin config form, organized in three tabs (General / Classic Admin /
  Admin2); labels/help/titles are translatable via `PLUGIN_PAGE_INSIGHTS.*` keys in
  `languages/*.yaml`
- `admin-next/pages/page-insights.js` — the Admin2 dashboard (single Web Component, Shadow DOM)
- `themes/admin/templates/` — Classic Admin (Grav 1.x) dashboard widgets and detail pages

See the plugin's own `README.md` for the full list of configuration options, and
`docs/ARCHITECTURE.md` (if present) for a deeper look at the Admin2 sub-routing approach and other
non-obvious design decisions.

## Release process (for context, maintainer-only)

Releases are created by hand on Codeberg (tag `v*`), which is push-mirrored to GitHub. A
`.github/workflows/release-from-tag.yml` workflow then turns that mirrored tag into a proper
GitHub Release automatically — this only exists to make the GitHub mirror useful for people
browsing there, not as the primary release process. It's deliberately scoped to `v*` tags only,
so internal/development tags never spawn a spurious GitHub release. You don't need to do anything
here as a contributor — just mention in your PR if you think a change warrants a version bump.

## License

This project is licensed under the MIT License. By submitting a pull request, you agree that your
contribution is provided under the same license.

---

## Auf Deutsch (Kurzfassung)

**Dieses GitHub-Repository ist nur ein Lese-Mirror.** Die eigentliche Entwicklung findet auf
[Codeberg](https://codeberg.org/chschmidt/grav-plugin-page-insights) statt. Bitte Bugs/Feature-Wünsche
und Pull Requests dort einreichen.

**Design-Ziele:** GPM-fähig ohne manuellen Eingriff (trotz einer echten Composer-Abhängigkeit,
`vendor/` ist deshalb committet), Kompatibilität mit **beiden** Admin-Oberflächen (Classic Admin
und Admin2) bei jeder Änderung im Blick behalten, SQL-Zugriffe über den bestehenden generischen
Filter-Mechanismus in `classes/Stats.php` statt neuer Spezialmethoden.

**Vor einem Pull Request:**
- Ziel-Branch ist immer `main` (nicht `develop` — der ist intern für größere Umbauten). Der
  `translate`-Branch gehört Weblate und ist ebenfalls nicht Teil des normalen Workflows.
- Unterstützt wird PHP >= 8.0 (siehe `composer.json`).
- Bei Änderungen an `classes/`-Namespaces oder `composer.json`s `autoload`/`require`-Abschnitten:
  unbedingt `composer dump-autoload` laufen lassen und die neu generierten
  `vendor/composer/*.php`-Dateien mit committen — sonst droht ein `Class "..." not found`-Fehler
  bei jedem frischen Checkout, obwohl `composer.json` selbst korrekt ist.
- `php -l` (und bei JS-Änderungen `node --check`) auf geänderten Dateien laufen lassen. PRs gegen
  `main` lösen zwar automatisch einen Syntax-Check in der CI aus, aber bitte trotzdem selbst
  vorher prüfen (CI läuft je nach Codeberg-Actions-Berechtigungen bei externen Forks evtl. gar
  nicht).
- Kurz in der PR-Beschreibung angeben, wie manuell getestet wurde (Grav-/PHP-Version, Classic
  Admin und/oder Admin2).
- Lieber kleinere, fokussierte PRs als große Sammel-Änderungen.

**Übersetzungen:** laufen über [Codeberg Translate](https://translate.codeberg.org/engage/grav-plugin-page-insights/)
(Weblate), nicht über normale Pull Requests — Details siehe englischer Abschnitt oben.

**Lizenz:** MIT. Mit einem Pull Request stimmst du zu, dass dein Beitrag unter derselben Lizenz
steht.
