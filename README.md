# Page Insights Plugin

[![Übersetzungsstatus](https://translate.codeberg.org/widget/grav-plugin-page-insights/svg-badge.svg)](https://translate.codeberg.org/engage/grav-plugin-page-insights/)

![](screenshot.png)

The **Page Insights** Plugin is an extension for [Grav CMS](http://github.com/getgrav/grav).

Enhanced page and user analytics for Grav, with full support for both the classic Admin (Grav 1.7) and Admin2 (Grav 2.0).

This plugin adds an entry to the admin plugin sidebar showing detailed page and visitor statistics about your site.

## History

Page Insights is an independent continuation of [Page Stats](https://github.com/francodacosta/grav-plugin-page-stats)
by Nuno Costa. After Page Stats saw no direct code changes from its maintainer for several years, two pull requests
were contributed upstream instead: [#54](https://github.com/francodacosta/grav-plugin-page-stats/pull/54) added
Admin2/Grav 2.0 compatibility, and [#56](https://github.com/francodacosta/grav-plugin-page-stats/pull/56) added the
Page/User Detail views, a reorganized config screen, and a number of bugfixes and UX improvements. Both were
eventually merged upstream. Development continues independently here under a new name, so further changes aren't
dependent on upstream review timing.

Full credit and thanks to Nuno Costa for creating and open-sourcing the original plugin - this project wouldn't
exist without it.

## Features

* Page views, unique visitors, and unique users, with time-series trend charts
* Top pages, countries (with flags), browsers, platforms, and users
* Page Detail / User Detail views, each with their own time-series chart and top-lists
* "Recently viewed pages" with load-more pagination, browser/platform columns
* IP fallback for anonymous (non-logged-in) visitors, individually traceable via their IP
* Works with both the classic Admin (Grav 1.7) and Admin2 (Grav 2.0) side by side

## First Run

When you first run this plugin it will create a new SQLite database to store the data; its location can be defined
in the plugin config, and defaults to ```user/data/page-insights.sqlite```.

> **Coming from Page Stats?** Page Insights uses a separate database file by default, so running both plugins in
> parallel during a transition won't double-count hits. If you'd rather carry over your existing history than start
> fresh, copy (not move, in case you want to keep Page Stats installed too) your existing
> `user/data/page-data.sqlite` to the new `user/data/page-insights.sqlite` path before the first run.

## Configuration

Before configuring this plugin, you should copy `user/plugins/page-insights/page-insights.yaml` to
`user/config/plugins/page-insights.yaml` and only edit that copy.

> Note:
> If the DB file does not exist it will be created on first run
>
> Bot detection is based on user agent, it is not perfect, but it does work well

Note that if you use the Admin Plugin, a file with your configuration named `page-insights.yaml` will be saved in
the `user/config/plugins/` folder once the configuration is saved in the Admin.

### Front Matter

You can exclude individual pages from analytics by disabling the plugin in the page front matter as follows:
```
---
page-insights:
    process: false
---
```

## Database Migrations/Updates

From time to time database changes are published to support new features. Migrations should happen automatically,
but if you get errors like `Column XYZ not found` do the following:

1. Create an empty file at `user/plugins/page-insights/data/migrations/MUST_MIGRATE`
2. Navigate to a page on your website

This will trigger the plugin to execute the database migration and will delete the `MUST_MIGRATE` file.

## Installation

Page Insights is not (yet) listed in the official GPM (Grav Package Manager) directory, so manual installation is
currently the way to go. GPM installation will be documented here if/when that changes.

### Manual Installation

1. Download the latest release from [Codeberg](https://codeberg.org/chschmidt/grav-plugin-page-insights) and unzip
   it under `/your/site/grav/user/plugins`.
2. Rename the extracted folder to `page-insights`.
3. Copy `user/plugins/page-insights/page-insights.yaml` to `user/config/plugins/page-insights.yaml` and only edit
   that copy.

### Admin Plugin

Once GPM listing is available, installing via the Admin Plugin's `Plugins` menu will also be an option.

## Usage

Just install and have fun! There is nothing else you need to do, the plugin works out of the box.

Have a look at the Grav error log to make sure the plugin is running fine.

## Credits

Country-level IP lookup is built by the plugin itself (on demand, from the Admin config screen -
see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md#geolocation-classesgeolocation)) from the public
delegated-stats data of the five Regional Internet Registries (RIPE NCC, ARIN, APNIC, LACNIC,
AFRINIC), combined and published daily by RIPE NCC on behalf of the
<a href="https://www.nro.net">Number Resource Organization (NRO)</a>. No third-party geolocation
database is bundled with or downloaded automatically by the plugin.

Country flags from <a href="https://flagcdn.com">https://flagcdn.com</a>.

Originally created by Nuno Costa as [Page Stats](https://github.com/francodacosta/grav-plugin-page-stats).

## To Do

- [X] Browser / device stats (based on user agent)
- [X] User behaviour (select a user and see their session history and page flows)
- [X] Top country stats
- [X] Page details (select a page and see detailed stats about it)
- [X] User details (select a user and see detailed stats about them)
- [X] Referer logging
- [X] Time on page
- [X] Admin2 (Grav 2.0) support
- [ ] Referer analysis, to better understand where visitors are coming from
- [ ] Overview limited to "real" content pages (`.md` files under `user/pages`, excluding assets/sitemap/robots.txt),
      with a date-range filter
- [X] i18n for Admin2 labels
- [ ] Locale-aware date formatting on the Admin2 dashboard (chart x-axis labels are
      still a fixed `DD.MM.` format regardless of admin language)
- [ ] World map view
- [ ] Show city-level stats on the country stats page
- [ ] Enable/disable front-end event collection
- [ ] Custom events triggered by JavaScript
