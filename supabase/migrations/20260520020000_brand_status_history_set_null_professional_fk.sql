-- Plan §28.17 audit DATA-1.
--
-- core.brand_status_history.professional_id was created with ON DELETE CASCADE
-- (see 20260505000001_create_brand_status_history.sql:4). After a 30-day
-- soft-delete grace window expires and PurgeSoftDeleted force-deletes the
-- professional, every status-transition audit row for that brand vanishes —
-- contradicting the table's intent as a permanent audit trail.
--
-- Fix: make the column nullable and switch the FK to ON DELETE SET NULL,
-- matching the precedent set in
-- 20260505200000_commission_ledger_entries_set_null_professional_fks.sql.
-- Audit rows survive professional purge; the professional_id column simply
-- decays to NULL, which is the standard "the actor is gone but the event
-- happened" signal.
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

ALTER TABLE core.brand_status_history
    VALIDATE CONSTRAINT brand_status_history_professional_id_fkey;

COMMIT;
