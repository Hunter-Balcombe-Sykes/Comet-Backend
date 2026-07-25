-- Profile design presets are computed at read time from core.users fields
-- (ProfileDesignPresets); the stored integration-factor contribution layer
-- is retired. Manual site.design_kits columns are untouched.
--
-- The table's writer code was deleted ahead of this drop (commits f15e53b0,
-- e2180ef9), so site.design_kit_contributions was already unused/empty at drop
-- time — the DROP is idempotent (IF EXISTS) and destroys no live data. The
-- lock_timeout / statement_timeout guards below follow the DDL-timeout
-- convention in docs/migration-guidelines.md: not required for a retired-table
-- drop (and outside guard:no-unsafe-migrations' ALTER/UPDATE scope), kept for
-- consistency so the next destructive migration inherits the same shape.
BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

drop table if exists site.design_kit_contributions;

COMMIT;
