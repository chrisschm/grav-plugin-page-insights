PRAGMA foreign_keys = off;

BEGIN TRANSACTION;

-- Scan-detection staging (see docs/ARCHITECTURE.md "Scan detection"): a
-- short-lived table capturing the RAW ip for every 404 hit, written
-- synchronously at collection time (PageInsightsPlugin::collectPageData(),
-- via Stats::recordScanCandidate()) independently of "anonymize_ips"/
-- "anonymize_ips_after" (which only ever touch "data.ip") and of
-- "log_bot"/"log_admin" (which only decide whether a hit is written to
-- "data" at all, for dashboard-cleanliness reasons unrelated to security -
-- a scanner spoofing a real browser's User-Agent, the documented common
-- case, would otherwise slip past "log_bot" undetected).
--
-- Without this table, Stats::detectScans() - which used to read "data"
-- directly - only ever saw whatever "data.ip" already was by the time it
-- ran every five minutes: harmless for the ordinary case, but under
-- "anonymize_ips" (immediate) it meant scan_alerts.ip only ever held the
-- already-masked address, of little use for actually blocking the
-- offending IP.
--
-- Deliberately no pattern-matching at write time: every 404 gets one row
-- here unconditionally, regardless of whether it matches anything in
-- "scan_patterns", so this table's per-request write cost is constant and
-- never scales with how large "scan_patterns" grows - preserving the
-- existing "no per-request performance cost" design goal for scan
-- detection (see ARCHITECTURE.md). Pattern matching itself still happens
-- only in the 5-minute batch job (Stats::detectScans()), which now reads
-- from this table instead of "data".
--
-- Deliberately no index: kept small by construction via aggressive
-- pruning (Stats::pruneScanStaging(), called right after every
-- detectScans() run from the same job) - rows only ever live a few
-- minutes, so a full table scan is never a concern here, unlike "data" or
-- "scan_alerts".
CREATE TABLE IF NOT EXISTS scan_staging (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip VARCHAR (255) NOT NULL,
    route VARCHAR (255) NOT NULL,
    date DATETIME NOT NULL,
    environment VARCHAR (255)
);

COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
