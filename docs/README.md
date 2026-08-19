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
  (`Stats::query()`), Admin2 sub-routing and i18n, the config blueprint, the geolocation subsystem
  (self-built RIR-based country lookup), CLI commands and automatic scheduling, the
  Composer/compiled-autoloader gotcha, and notable past bugs.

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
  Admin-Oberflächen auf gemeinsamer Datenschicht, generischer Filter-Mechanismus, Admin2-Routing
  und i18n, Geolocation-Subsystem, CLI-Kommandos/Scheduler, bekannte Altlasten.
- [`../SECURITY.md`](../SECURITY.md), [`../CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md) -
  Richtlinien.
- [`../CHANGELOG.md`](../CHANGELOG.md) - veröffentlichte Versionen.
- CI: [`../.forgejo/workflows/lint.yml`](../.forgejo/workflows/lint.yml) prüft jeden PR gegen
  `main` (PHP/JS/Twig-Syntax), aktuell nicht garantiert für externe Pull Requests.
  [`../.github/workflows/release-from-tag.yml`](../.github/workflows/release-from-tag.yml) ist ein
  reiner GitHub-Mirror-Workflow, kein Teil des regulären Beitrags-Ablaufs.
