-- Plan §28.17 audit DATA-1 — part 1 of 2 (column + FK swap).
--
-- core.brand_status_history.professional_id was created with ON DELETE CASCADE
-- (see 20260505000001_create_brand_status_history.sql:4). After a 30-day
-- soft-delete grace window expires and PurgeSoftDeleted force-deletes the
-- professional, every status-transition audit row for that brand vanishes —
-- contradicting the table's intent as a permanent audit trail.
--
-- Fix: drop the CASCADE FK + make the column nullable, then re-add as ON
-- DELETE SET NULL NOT VALID. Audit rows survive professional purge; the
-- column simply decays to NULL.
--
-- VALIDATE runs in the next migration (20260520020100) in its own transaction
-- per CONVENTIONS §4 — the NOT VALID benefit (avoiding a full-table scan
-- under ACCESS EXCLUSIVE) is only realised when VALIDATE lives outside this
-- file's txn.
--
-- To revert:
--   ALTER TABLE core.brand_status_history
--     DROP CONSTRAINT brand_status_history_professional_id_fkey,
--     ALTER COLUMN professional_id SET NOT NULL,
--     ADD CONSTRAINT brand_status_history_professional_id_fkey
--       FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE CASCADE;

BEGIN;

ALTER TABLE core.brand_status_history
    DROP CONSTRAINT IF EXISTS brand_status_history_professional_id_fkey;

ALTER TABLE core.brand_status_history
    ALTER COLUMN professional_id DROP NOT NULL;

ALTER TABLE core.brand_status_history
    ADD CONSTRAINT brand_status_history_professional_id_fkey
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL NOT VALID;

COMMIT;
