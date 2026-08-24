# Contributor Documentation Index

This is the starting point for anyone who wants to work on the `page-insights` **code** - plugin
contributors and maintainers. If you're a site administrator looking for installation or
configuration help, see the
[Wiki](https://codeberg.org/chschmidt/grav-plugin-page-insights/wiki) instead; nothing here
duplicates that.

## Start here

- [`../CONTRIBUTING.md`](../CONTRIBUTING.md) - how to propose changes, the PR checklist, the
  translation workflow (including the maintainer-only `translate`-branch mechanics), the design
  goals any change needs to respect, and the plugin's history as an independent continuation of
  Page Stats.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) - how the plugin is built and why: file layout, the two
  supported Admin UIs (Classic Admin vs. Admin2) sharing one data layer, the generic query filter
  (`Stats::query()`), the config blueprint, and the Composer/compiled-autoloader and CI gotchas.
  Start here, then follow into the topic files below as needed.
- [`DATABASES.md`](DATABASES.md) - schema and storage-level design decisions for both on-disk
  stores: the SQLite stats/events database (tables, indexes, connection pragmas) and the self-built
  geo country index binary format. Kept separate from `ARCHITECTURE.md` so there's one place to
  update when a schema changes.
- [`GEOLOCATION.md`](GEOLOCATION.md) - the country-lookup subsystem: the self-built RIR-based
  index, the three classes that build and read it, and the prebuilt-vs-raw update modes.
- [`ADMIN-UI.md`](ADMIN-UI.md) - Admin2's client-side sub-routing, its i18n bridge, localized date
  formatting on both admin sides, and the dashboard-wide "Hide bots" filter.
- [`MAINTENANCE.md`](MAINTENANCE.md) - CLI commands, the Admin2 database maintenance dialog, and
  the automatic scheduler jobs (geo-db updates, data pruning, rollups, scan detection).
- [`HISTORY.md`](HISTORY.md) - a numbered list of non-obvious past bugs, their root cause, and the
  reasoning behind each fix - useful context before touching related code.

## Policies

- [`../SECURITY.md`](../SECURITY.md) - how to report a vulnerability, supported versions, scope.
- [`../CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md) - community standards (Contributor
  Covenant).

## Project history

- [`../CHANGELOG.md`](../CHANGELOG.md) - released versions.

## Continuous integration

Every pull request against `main` is checked by Forgejo Actions on Codeberg (PHP/JS/Twig syntax)
- see [`../.forgejo/workflows/lint.yml`](../.forgejo/workflows/lint.yml). This may not run for
pull requests from outside contributors depending on Codeberg's Actions permissions for forks;
see `CONTRIBUTING.md` for the manual syntax-check step to run instead. A separate,
GitHub-only workflow, [`../.github/workflows/release-from-tag.yml`](../.github/workflows/release-from-tag.yml),
turns a mirrored release tag into a proper GitHub Release automatically - it only exists to make
the GitHub mirror useful for people browsing there and isn't part of the regular contribution
flow.

---

## Auf Deutsch (Kurzfassung)

Ausgangspunkt für alle, die am Code arbeiten wollen (Contributor/Maintainer). Anwender-Doku
(Installation, Konfiguration, Verwendung, Fehlerbehebung) gibt es stattdessen im
[Wiki](https://codeberg.org/chschmidt/grav-plugin-page-insights/wiki) - hier keine Duplikate.

- [`../CONTRIBUTING.md`](../CONTRIBUTING.md) - Vorgehen für Änderungen, PR-Checkliste,
  Übersetzungs-Workflow (inkl. `translate`-Branch-Mechanik für Maintainer), Design-Vorgaben sowie
  die Projektgeschichte als unabhängige Fortführung von Page Stats.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) - Aufbau und Design-Entscheidungen: Dateilayout, die beiden
  Admin-Oberflächen auf gemeinsamer Datenschicht, generischer Filter-Mechanismus, Config-Blueprint,
  Composer/Autoloader- und CI-Stolpersteine. Guter Startpunkt, von dort aus in die Themendateien
  unten verlinkt.
- [`DATABASES.md`](DATABASES.md) - Schema und Design-Entscheidungen beider Datenspeicher: die
  SQLite-Statistik-/Events-Datenbank (Tabellen, Indizes, Connection-Pragmas) und das selbstgebaute
  Binärformat des Geo-Country-Index. Bewusst getrennt von `ARCHITECTURE.md` gehalten, damit es genau
  eine Stelle für Schemaänderungen gibt.
- [`GEOLOCATION.md`](GEOLOCATION.md) - das Geolocation-Subsystem: selbstgebauter RIR-basierter
  Index, die drei beteiligten Klassen, Prebuilt- vs. Raw-Build-Modus.
- [`ADMIN-UI.md`](ADMIN-UI.md) - Admin2-Sub-Routing, i18n-Bridge, lokalisierte Datumsformatierung
  auf beiden Admin-Seiten, "Bots ausblenden"-Filter.
- [`MAINTENANCE.md`](MAINTENANCE.md) - CLI-Befehle, Admin2-Datenbankpflegedialog, automatische
  Scheduler-Jobs (Geo-DB-Updates, Datenbereinigung, Rollups, Scan-Erkennung).
- [`HISTORY.md`](HISTORY.md) - nummerierte Liste nicht-offensichtlicher vergangener Bugs samt
  Ursache und Fix-Begründung.
- [`../SECURITY.md`](../SECURITY.md), [`../CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md) -
  Richtlinien.
- [`../CHANGELOG.md`](../CHANGELOG.md) - veröffentlichte Versionen.
- CI: [`../.forgejo/workflows/lint.yml`](../.forgejo/workflows/lint.yml) prüft jeden PR gegen
  `main` (PHP/JS/Twig-Syntax), aktuell nicht garantiert für externe Pull Requests.
  [`../.github/workflows/release-from-tag.yml`](../.github/workflows/release-from-tag.yml) ist ein
  reiner GitHub-Mirror-Workflow, kein Teil des regulären Beitrags-Ablaufs.
