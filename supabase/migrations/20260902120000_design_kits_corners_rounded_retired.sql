-- Corners: the 'rounded' rung (1rem) is retired (owner, 2026-09-02, batch 2
-- D.2) — square ('sharp') or curved ('default'), nothing softer. Rows that
-- picked it read as curved from now on. Data only: a backfill, so no
-- BEGIN/COMMIT (CONVENTIONS.md §5) and a session-level lock bound. The
-- column's allowed set is application-side (DesignKitValidationRules).
-- ROLLBACK: nothing to undo — 'rounded' no longer validates, and the kit's
-- resolver reads any surviving 'rounded' as 'default' anyway.
SET lock_timeout = '5s';
SET statement_timeout = '60s';

UPDATE site.design_kits SET corners = 'default' WHERE corners = 'rounded';
