PRAGMA foreign_keys = off;

BEGIN TRANSACTION;

-- Deferred IP anonymization (see docs/ARCHITECTURE.md "IP anonymization"
-- and docs/DATABASES.md): tracks whether "data.ip" already holds a masked
-- value - either because "anonymize_ips" masked it immediately at
-- collection time, or because the scheduled job
-- (Stats::anonymizeAgedIps(), config "anonymize_ips_after") has since
-- masked it retroactively.
--
-- Deliberately NOT derived from the "ip" string's shape (e.g. "ends in
-- .0.0"): whether an address with all-zero low bits is actually reserved
-- (network address) depends on the specific subnet mask it was allocated
-- with, which isn't knowable from the address alone - a real client IP can
-- legitimately end that way too. A pattern-matched sentinel would risk
-- silently never anonymizing such a row. Added nullable/default 0 with no
-- backfill for existing rows - Stats::maskIp() is idempotent (re-masking an
-- already-masked value returns it unchanged), so a pre-existing row that
-- was already masked under the old "anonymize_ips" (immediate) toggle is
-- simply re-masked as a no-op the first time the deferred job reaches it,
-- rather than guessing per-row history that was never recorded.
--
-- No index: anonymizeAgedIps() filters via "idx_data_date_normalized"
-- first (same datetime(date)-wrapped comparison as every other date-range
-- query in this class), then narrows to "ip_anonymized = 0" only within
-- that already date-narrowed result - same reasoning already documented
-- for the low-cardinality "environment" column (see docs/DATABASES.md
-- "Indexes").
ALTER TABLE data ADD COLUMN ip_anonymized BOOLEAN DEFAULT 0;

COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
