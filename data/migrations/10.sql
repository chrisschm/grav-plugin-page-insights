PRAGMA foreign_keys = off;

BEGIN TRANSACTION;

-- Scan detection (see docs/ARCHITECTURE.md "Scan detection" and
-- docs/DATABASES.md "Tables scan_patterns / scan_alerts"): an opt-in feature
-- that periodically checks recent 404 hits already collected in "data"
-- against a list of known vulnerability-scan paths, and raises an alert when
-- one IP racks up enough distinct matches in a short window to look like
-- automated probing rather than a stray broken link.

-- Table: scan_patterns - the signature list "data.route" is matched against
-- (plain substring match, not a regex engine - see Stats::detectScans()).
-- Starts empty on every install; populated either by
-- `bin/plugin page-insights scan-patterns:import` (seeds from the bundled
-- data/scan-patterns-webexploits.txt snapshot - see that file's own header
-- for provenance/license) or manually via the Admin2 "Scan detection" view.
-- "pattern" is UNIQUE so both of those paths can freely re-insert the same
-- value without duplicating rows - see importScanPatterns()'s
-- insert-if-missing logic, which relies on this constraint.
CREATE TABLE IF NOT EXISTS scan_patterns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pattern VARCHAR (500) NOT NULL UNIQUE,
    source VARCHAR (100),
    added_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
    enabled BOOLEAN DEFAULT (1)
);

-- Table: scan_alerts - one row per (ip, still-open incident), upserted by
-- the scheduled detection job every time it runs (see
-- PageInsightsPlugin::registerScanDetectionJob(), Stats::detectScans()).
-- "matched_routes" is a newline-joined, capped list of the actual requested
-- paths that triggered the alert (for display - see docs/DATABASES.md) not a
-- normalized child table, since this is a small, best-effort diagnostic
-- summary, not data anything else queries by matched-route.
-- "notified_at" is set once an alert has actually been emailed
-- (Job::email(), see registerScanDetectionJob()) so re-running the job every
-- five minutes doesn't re-send mail for an already-reported, still-ongoing
-- incident - the Admin2 dashboard notification (onApiDashboardNotifications)
-- is unaffected by this column, it always reflects live scan_alerts state.
CREATE TABLE IF NOT EXISTS scan_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip VARCHAR (255) NOT NULL,
    first_seen DATETIME NOT NULL,
    last_seen DATETIME NOT NULL,
    hit_count INTEGER NOT NULL DEFAULT (0),
    matched_routes TEXT,
    notified_at DATETIME,
    environment VARCHAR (255)
);

CREATE INDEX IF NOT EXISTS idx_scan_alerts_ip ON scan_alerts (ip);
CREATE INDEX IF NOT EXISTS idx_scan_alerts_last_seen ON scan_alerts (last_seen);

COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
