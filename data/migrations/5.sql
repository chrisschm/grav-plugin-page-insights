PRAGMA foreign_keys = off;

BEGIN TRANSACTION;

CREATE INDEX IF NOT EXISTS idx_data_route ON data (route);
CREATE INDEX IF NOT EXISTS idx_data_date ON data (date);

COMMIT TRANSACTION;
PRAGMA foreign_keys = on;
