PRAGMA foreign_keys = off;

BEGIN TRANSACTION;

-- Extends the daily rollup infrastructure from migration 7 (see
-- docs/DATABASES.md, "Rollups") to the three remaining "top N with a
-- share%" dashboard widgets: topCountries()/topBrowsers()/topPlatforms().
-- statusCodeSummary()/totalUniqueVisitors()/totalUniqueUsers()/
-- siteSummary() are covered by this same migration too, but need no new
-- table - they're all already answerable from rollup_daily's existing
-- hits/visitors/users/http_200/http_404/http_other columns.
--
-- Three separate narrow tables, not one - same reasoning as rollup_route
-- vs rollup_daily in migration 7: a single (day, is_bot, country, browser,
-- platform) cross-product table would multiply row counts by every
-- combination of all three dimensions, most of which no query here ever
-- asks for together. Deliberately country/browser/platform ONLY, no
-- "route" column, unlike rollup_route: topCountries()/topBrowsers()/
-- topPlatforms() are called with a per-route filter from the Page Detail
-- view (see page-details.html.twig) and a per-visitor filter from the User
-- Detail view (user-details.html.twig) - both real, but per-page or
-- per-visitor breakdowns of country/browser/platform, unlike per-page
-- *hit counts*, were never what this rollup work set out to speed up (see
-- migration 7's own comment on the ~1M-hits/month dashboard-load benchmark
-- that motivated all of this - a Page/User Detail view is a single-entity
-- lookup, not the full-dashboard aggregate scan this rollup targets).
-- Stats::topCountries() etc.'s rollup fast path is therefore, deliberately,
-- narrower than pagesSummary()'s: only date-range + optional "hide bots"
-- calls use it; a route/user/ip-filtered call keeps using the original
-- live query, exactly like pagesSummary() already does for user/ip.
--
-- "hits" only (no visitors/users, unlike rollup_route) - none of
-- topCountries()/topBrowsers()/topPlatforms() have ever returned a
-- visitors/users figure, only "hits" and a computed "share" percentage.
CREATE TABLE IF NOT EXISTS rollup_country (
    day     DATE          NOT NULL,
    is_bot  BOOLEAN       NOT NULL,
    country VARCHAR (65)  NOT NULL,
    hits    INTEGER       NOT NULL,
    PRIMARY KEY (day, is_bot, country)
);

CREATE TABLE IF NOT EXISTS rollup_browser (
    day     DATE          NOT NULL,
    is_bot  BOOLEAN       NOT NULL,
    browser STRING (100)  NOT NULL,
    hits    INTEGER       NOT NULL,
    PRIMARY KEY (day, is_bot, browser)
);

CREATE TABLE IF NOT EXISTS rollup_platform (
    day      DATE          NOT NULL,
    is_bot   BOOLEAN       NOT NULL,
    platform VARCHAR (255) NOT NULL,
    hits     INTEGER       NOT NULL,
    PRIMARY KEY (day, is_bot, platform)
);

COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
