-- Plan §28.17 audit DATA-2 — part 1 of 2 (column + FK swap).
--
-- core.handle_change_log.professional_id was created with ON DELETE CASCADE
-- (see 20260519100000_handle_alias_lifecycle.sql:90). After a 30-day
-- soft-delete grace expires and PurgeSoftDeleted force-deletes the
-- professional, every handle-rename audit row for that pro vanishes —
-- contradicting the table's stated 7-year retention rule in the comment block
-- at the top of that same migration ("Audit log: append-only, retained per
-- config (default 7 years)").
--
-- Fix: drop the CASCADE FK, make the column nullable, re-add as ON DELETE
-- SET NULL NOT VALID. Audit rows survive professional purge; the column
-- simply decays to NULL once the Professional row is gone. The 7-year
-- retention is then enforced by PurgeRawAnalyticsEvents-style sweeps over
-- `changed_at`, NOT by FK cascade behavior.
--
-- Mirrors 20260520020000_brand_status_history_set_null_professional_fk.sql
-- (DATA-1) exactly. VALIDATE runs in 20260523000100 in its own transaction.
--
-- To revert:
--   ALTER TABLE core.handle_change_log
--     DROP CONSTRAINT handle_change_log_professional_id_fkey,
--     ALTER COLUMN professional_id SET NOT NULL,
--     ADD CONSTRAINT handle_change_log_professional_id_fkey
--       FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

BEGIN;

ALTER TABLE core.handle_change_log
    DROP CONSTRAINT IF EXISTS handle_change_log_professional_id_fkey;

ALTER TABLE core.handle_change_log
    ALTER COLUMN professional_id DROP NOT NULL;

ALTER TABLE core.handle_change_log
    ADD CONSTRAINT handle_change_log_professional_id_fkey
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL NOT VALID;

COMMIT;
