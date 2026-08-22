PRAGMA foreign_keys = off;

BEGIN TRANSACTION;

-- Daily rollup infrastructure (see docs/DATABASES.md, "Rollups"). Every
-- dashboard aggregate query used to scan the full matched row range of
-- "data" on every load - fine at ~100k hits/month, but EXPLAIN QUERY PLAN
-- confirmed (2026-08-22, synthetic 3M-row benchmark) that even with a
-- correctly-matched index the GROUP BY/ORDER BY/COUNT(DISTINCT) work after
-- the index seek still scales with the number of matched rows, not the
-- index: a single pagesSummary() call over a 90-day/3M-row range took
-- ~12.9s, totalUniqueVisitors() ~17.4s - a full dashboard load reached
-- ~115s. These two tables let Stats::rollupDay() precompute one row per
-- (day, is_bot[, route]) once, off the request path, so query time scales
-- with the number of *days* in the selected range instead of the number of
-- matched hits.
--
-- "visitors"/"users" here are COUNT(DISTINCT ip)/COUNT(DISTINCT user) for
-- that single day only - exact per day, but summing them across multiple
-- days (which is all a rollup can cheaply do) overcounts anyone who visited
-- on more than one of those days. Deliberate, documented approximation
-- (user-confirmed 2026-08-22) rather than the two more "correct"
-- alternatives - HyperLogLog sketches (real complexity/dependency for a
-- small plugin) or leaving the multi-day distinct count fully live (which
-- the benchmark showed doesn't actually solve the performance problem:
-- COUNT(DISTINCT ip) alone, with no GROUP BY at all, was already one of
-- the single most expensive queries measured).
CREATE TABLE IF NOT EXISTS rollup_daily (
    day        DATE      NOT NULL,
    is_bot     BOOLEAN   NOT NULL,
    hits       INTEGER   NOT NULL,
    visitors   INTEGER   NOT NULL, -- approx over >1 day, see above
    users      INTEGER   NOT NULL, -- approx over >1 day, see above
    http_200   INTEGER   NOT NULL,
    http_404   INTEGER   NOT NULL,
    http_other INTEGER   NOT NULL,
    PRIMARY KEY (day, is_bot)
);

-- Keyed by page_title, not route, deliberately mirroring
-- Stats::pagesSummary()'s existing "GROUP BY page_title" (not "GROUP BY
-- route") - a pre-existing quirk (two different routes that happen to
-- share a title would already merge into one row in the live query, and
-- SQLite's lenient bare-column GROUP BY extension picks an unspecified
-- "route" for that group). Rolling up by route instead would silently
-- change that grouping semantics as a side effect of a performance change,
-- and would stop the rollup part and the live-fallback tail (still the
-- original query, see pagesSummaryViaRollup()) from merging correctly for
-- the same page across the boundary. "route" here is just MIN(route) per
-- group - deterministic, but can differ from whatever the live query's own
-- unspecified pick would have been for a title shared by >1 route; not
-- pursued further, only relevant for that pre-existing edge case.
CREATE TABLE IF NOT EXISTS rollup_route (
    day         DATE          NOT NULL,
    is_bot      BOOLEAN       NOT NULL,
    page_title  VARCHAR (255) NOT NULL,
    route       VARCHAR (255),
    hits        INTEGER       NOT NULL,
    visitors    INTEGER       NOT NULL, -- approx over >1 day, see rollup_daily above
    users       INTEGER       NOT NULL, -- approx over >1 day, see rollup_daily above
    PRIMARY KEY (day, is_bot, page_title)
);

-- One row per named rollup job, tracking the most recent calendar day
-- that's been (re)computed - lets Stats::pagesSummary() etc. know how far
-- the rollup tables reach and fall back to a live query against "data" for
-- whatever's newer (normally just the still-accumulating current day), and
-- lets RollupBuildCommand catch up any gap (e.g. a missed scheduler run)
-- instead of only ever handling exactly "yesterday". Deliberately not
-- derived from MAX(day) in rollup_daily/rollup_route: a real calendar day
-- with zero traffic at all (bots or humans) would write no rows to either
-- table, which MAX(day) can't distinguish from "not rolled up yet".
CREATE TABLE IF NOT EXISTS rollup_state (
    job                 VARCHAR (64) PRIMARY KEY,
    last_rolled_up_day  DATE
);

COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
