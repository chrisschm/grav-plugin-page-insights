# Security Policy

*(Eine deutsche Kurzfassung findest du am Ende dieser Datei.)*

## Reporting a vulnerability

Please **do not** report security vulnerabilities through public GitHub or Codeberg issues,
discussions, or pull requests. Codeberg/Forgejo does not currently offer a private vulnerability
reporting feature comparable to GitHub's, so please report privately by email instead:

**security@jcs-net.de**

Please include, as far as you can:

- A description of the vulnerability and its potential impact
- Steps to reproduce (affected plugin version, Grav version, PHP version, Admin UI used –
  Admin2/Classic Admin)
- Any proof-of-concept code or example request/parameter combination that triggers the issue

You should receive an acknowledgement within a few days. This is a small, solo-maintained
open-source project without a dedicated security team, so please allow reasonable time for a fix
before any public disclosure. I'll coordinate a disclosure timeline with you once the report is
confirmed.

## Supported versions

Only the latest released version of the plugin (as published via GPM) is supported with security
fixes. Please make sure you're on the current version before reporting, and update to the fixed
version as soon as a patch is released.

## Scope

This plugin collects and stores page/visitor statistics (including IP addresses) in a local
SQLite database and exposes them via REST endpoints and an Admin2 dashboard. Reports particularly
welcome around:

- SQL injection via the generic query-filter mechanism in `classes/Stats.php`
  (`Stats::query()` and the various `$params`-based filter methods)
- Missing or bypassable authentication/authorization on the REST endpoints in
  `classes/Api/PageInsightsApiController.php` (these are expected to be gated by Grav's Admin2
  API session, not independently authenticated)
- XSS via stored, user-influenced values (page routes, usernames, browser/platform strings)
  rendered in `admin-next/pages/page-insights.js`
- Handling of visitor IP data in `classes/Geolocation/*.php`, including interaction with the
  bundled IP2Location database

This plugin does not fetch remote URLs on a schedule or on user input (no feed/SSRF surface); the
IP2Location lookup is against the locally bundled database only. General Grav core or
hosting/infrastructure vulnerabilities are out of scope here — please report those to the Grav
project or your hosting provider directly.

---

## Auf Deutsch (Kurzfassung)

**Sicherheitslücken bitte nicht** als öffentliches Issue auf GitHub oder Codeberg melden, sondern
per E-Mail an **security@jcs-net.de**. Bitte möglichst mit Beschreibung, Auswirkung,
Reproduktionsschritten (Plugin-/Grav-/PHP-Version, genutzte Admin-Oberfläche) und ggf. einem
Proof-of-Concept.

Unterstützt wird nur die jeweils aktuelle, über GPM veröffentlichte Version. Da es sich um ein
Solo-Projekt ohne dediziertes Security-Team handelt, bitte etwas Zeit für einen Fix einplanen,
bevor öffentlich darüber gesprochen wird — ich melde mich zeitnah zurück und stimme einen
Offenlegungszeitpunkt mit dir ab.

Besonders willkommen sind Hinweise zu: SQL-Injection über den generischen Filter-Mechanismus in
`classes/Stats.php`, fehlender/umgehbarer Absicherung der REST-Endpunkte in
`classes/Api/PageInsightsApiController.php`, XSS über gespeicherte Werte (Seiten-Routen,
Nutzernamen, Browser-/Plattform-Strings) im Admin2-Dashboard sowie dem Umgang mit IP-Daten in
`classes/Geolocation/*.php`.
