PRAGMA foreign_keys = off;

BEGIN TRANSACTION;

-- Every date-range filter in Stats::query() (and pruneData()'s cutoff)
-- compares "datetime(date) BETWEEN datetime(:from) AND datetime(:to)", not
-- a plain "date BETWEEN ..." - required because "date" carries whatever UTC
-- offset was locally in effect at write time, so a plain text comparison
-- gives wrong results across rows written under different offsets (see
-- DATABASES.md, "Date storage and comparison"). That datetime() wrapping
-- means idx_data_date (migration 5, on the raw "date" column) can never
-- match this comparison - confirmed via EXPLAIN QUERY PLAN: every dashboard
-- query that filters by date range was doing a full table SCAN of "data"
-- despite the index, on every request, getting slower as the table grows.
-- This expression index lets SQLite match the exact same datetime(date)
-- expression the WHERE clause already uses, turning that SCAN into a
-- SEARCH. Kept alongside, not instead of, idx_data_date, which still
-- covers a different query shape: recentPages()'s unfiltered
-- "ORDER BY date DESC LIMIT n".
CREATE INDEX IF NOT EXISTS idx_data_date_normalized ON data (datetime(date));

-- Stats::timeOnPage() is called once per displayed row by Classic Admin's
-- "Recently viewed pages" widget (recently-viewed-pages.html.twig) to filter
-- "events" by session_id - up to 1000 times on the dedicated "view last 1000
-- pages" page. Without this index, each of those was its own full table
-- SCAN of "events". Also speeds up collectEvent()'s own per-hit session
-- lookup on the unauthenticated /event-collection endpoint.
CREATE INDEX IF NOT EXISTS idx_events_session_id ON events (session_id);

COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
