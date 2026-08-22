PRAGMA foreign_keys = off;

BEGIN TRANSACTION;

-- Multisite/environment scoping (Codeberg Issue #3): a Grav installation
-- serving several sites from one shared installation (Grav's own
-- multi-site mechanism, see learn.getgrav.org/17/advanced/multisite-setup)
-- previously had every site's hits/aggregates mixed together, since
-- neither "data" nor any rollup table had a column identifying which site
-- a row belonged to.
--
-- "environment" deliberately reuses Grav's own concept
-- (config('environment'), Grav\Common\Config\Setup - defaults to the
-- current request's hostname, with an admin-configurable alias mechanism
-- for merging e.g. "www." and the bare domain into one environment)
-- rather than inventing a page-insights-specific "site" identifier. A
-- single-site install has exactly one environment value across every row
-- it ever writes, so this column (and every query change that reads it)
-- is a provable no-op for the overwhelmingly common case: the added WHERE
-- condition/GROUP BY column never actually narrows or splits anything
-- when there is only ever one distinct value to compare against.
--
-- Nullable, no default: existing "data" rows (written before this
-- migration) get NULL. Deliberately NOT backfilled to any guessed value -
-- there is no reliable way to attribute already-collected, already-mixed
-- historical hits to one particular site after the fact. Every read path
-- treats a NULL environment as visible to *every* site
-- ("environment = :environment OR environment IS NULL") rather than
-- hidden from all of them: a fresh install after this version never has
-- such rows, and an upgraded install keeps its pre-upgrade history
-- visible on every site's dashboard exactly as before, while every *new*
-- hit from this point on is correctly split by site. (User-confirmed
-- trade-off - see docs/DATABASES.md, "Multisite (environment) scoping".)
ALTER TABLE data ADD COLUMN environment VARCHAR (255);

-- Deliberately NO index on "environment" - tried and measured, then
-- reverted (2026-08-2x benchmark, see docs/DATABASES.md). The read-path
-- condition is "(environment = :environment OR environment IS NULL)"
-- (see query()'s docblock) - with an index on "environment" present,
-- SQLite's planner chose a MULTI-INDEX OR plan driven by that index (two
-- index seeks, one per OR branch) *instead of* idx_data_date_normalized,
-- even for queries that also carry a narrow date-range condition (e.g.
-- the single-boundary-day live queries every rollup-backed method falls
-- back to - see "Read path" below). Since environment has low
-- cardinality (a handful of distinct values on even a large multisite
-- install), each OR branch's index seek matches a large fraction of the
-- whole table, and the date range then has to be re-checked row-by-row
-- across all of them - the exact opposite of what idx_data_date_normalized
-- was added for in migration 6. Measured on the same 3M-row/90-day
-- synthetic database as the rollup benchmark: a single boundary-day query
-- went from ~34ms (idx_data_date_normalized, no environment index) to
-- ~830ms (MULTI-INDEX OR via idx_data_environment) - worse by roughly
-- 24x, and multiplied by however many such boundary queries a dashboard
-- load makes (two per rollup-backed widget, more for siteSummary()'s
-- three-query shape). Without an index on "environment" at all, there is
-- nothing to lure the planner away from idx_data_date_normalized -
-- confirmed via EXPLAIN QUERY PLAN to fall back to exactly the same plan
-- as before this column existed, with "environment" applied as a plain
-- filter over the already date-narrowed rows.

-- The five rollup tables (migrations 7/8) each need "environment" added
-- to their PRIMARY KEY - SQLite has no ALTER TABLE support for changing a
-- primary key in place, so each is recreated (empty) rather than altered.
-- Existing rollup rows are not carried forward: they already aggregate
-- every site's hits together (there was no environment column for
-- rollupDay() to GROUP BY), so - exactly like the "data" rows above -
-- there is no reliable way to split them apart after the fact.
--
-- This is safe under the same idempotent-rebuild guarantee rollupDay()
-- already documents ("safe to rebuild any day ... never double-counts"):
-- dropping these tables and clearing rollup_state (below) makes
-- rollupStatus() return null immediately after this migration, so every
-- rollup-backed Stats method transparently falls back to its live query -
-- exactly like a fresh install that has never run `rollup:build` yet -
-- until the next `rollup:build` run (manual, or the scheduled
-- rollup_auto_build job) repopulates all five tables from "data",
-- correctly split by environment this time. See CHANGELOG.md for the
-- upgrade note telling admins to (re-)run `rollup:build` after updating.
DROP TABLE IF EXISTS rollup_daily;
CREATE TABLE rollup_daily (
    day         DATE          NOT NULL,
    is_bot      BOOLEAN       NOT NULL,
    environment VARCHAR (255),
    hits        INTEGER       NOT NULL,
    visitors    INTEGER       NOT NULL,
    users       INTEGER       NOT NULL,
    http_200    INTEGER       NOT NULL,
    http_404    INTEGER       NOT NULL,
    http_other  INTEGER       NOT NULL,
    PRIMARY KEY (day, is_bot, environment)
);

DROP TABLE IF EXISTS rollup_route;
CREATE TABLE rollup_route (
    day         DATE          NOT NULL,
    is_bot      BOOLEAN       NOT NULL,
    environment VARCHAR (255),
    page_title  VARCHAR (255) NOT NULL,
    route       VARCHAR (255),
    hits        INTEGER       NOT NULL,
    visitors    INTEGER       NOT NULL,
    users       INTEGER       NOT NULL,
    PRIMARY KEY (day, is_bot, environment, page_title)
);

DROP TABLE IF EXISTS rollup_country;
CREATE TABLE rollup_country (
    day         DATE          NOT NULL,
    is_bot      BOOLEAN       NOT NULL,
    environment VARCHAR (255),
    country     VARCHAR (65)  NOT NULL,
    hits        INTEGER       NOT NULL,
    PRIMARY KEY (day, is_bot, environment, country)
);

DROP TABLE IF EXISTS rollup_browser;
CREATE TABLE rollup_browser (
    day         DATE          NOT NULL,
    is_bot      BOOLEAN       NOT NULL,
    environment VARCHAR (255),
    browser     STRING (100)  NOT NULL,
    hits        INTEGER       NOT NULL,
    PRIMARY KEY (day, is_bot, environment, browser)
);

DROP TABLE IF EXISTS rollup_platform;
CREATE TABLE rollup_platform (
    day         DATE          NOT NULL,
    is_bot      BOOLEAN       NOT NULL,
    environment VARCHAR (255),
    platform    VARCHAR (255) NOT NULL,
    hits        INTEGER       NOT NULL,
    PRIMARY KEY (day, is_bot, environment, platform)
);

-- Cleared, not dropped (rollupDay()'s ON CONFLICT upsert re-creates its
-- one row regardless) - the important part is that "last_rolled_up_day"
-- no longer claims coverage the now-empty tables above don't actually
-- have; see the comment above.
DELETE FROM rollup_state;

COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
