-- Plan §28.16 Migration B / audit DATA-3 part B.
--
-- brand.brand_partner_link_events FKs to core.professionals were ON DELETE
-- RESTRICT (see 20260420000000_add_brand_partner_link_events.sql:6-7). With
-- soft-deletes on brand_partner_links, the audit table outlives the link rows
-- it describes — but if a professional is hard-deleted at the end of the
-- soft-delete grace window, PurgeSoftDeleted::forceDelete() throws an FK
-- violation against this audit table. The professional stays permanently
-- soft-deleted, thinking the account is gone.
--
-- Fix: drop RESTRICT, make both FK columns nullable, re-add ON DELETE SET
-- NULL. Matches the precedent set in
-- 20260505200000_commission_ledger_entries_set_null_professional_fks.sql.
--
-- To revert: see migration body — restore RESTRICT and re-add NOT NULL.
--   ALTER TABLE brand.brand_partner_link_events
--     ALTER COLUMN brand_professional_id SET NOT NULL,
--     ALTER COLUMN affiliate_professional_id SET NOT NULL,
--     DROP CONSTRAINT brand_partner_link_events_brand_professional_id_fkey,
--     DROP CONSTRAINT brand_partner_link_events_affiliate_professional_id_fkey,
--     ADD CONSTRAINT brand_partner_link_events_brand_professional_id_fkey
--       FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE RESTRICT,
--     ADD CONSTRAINT brand_partner_link_events_affiliate_professional_id_fkey
--       FOREIGN KEY (affiliate_professional_id) REFERENCES core.professionals(id) ON DELETE RESTRICT;

BEGIN;

ALTER TABLE brand.brand_partner_link_events
    DROP CONSTRAINT IF EXISTS brand_partner_link_events_brand_professional_id_fkey;

ALTER TABLE brand.brand_partner_link_events
    DROP CONSTRAINT IF EXISTS brand_partner_link_events_affiliate_professional_id_fkey;

ALTER TABLE brand.brand_partner_link_events
    ALTER COLUMN brand_professional_id DROP NOT NULL;

ALTER TABLE brand.brand_partner_link_events
    ALTER COLUMN affiliate_professional_id DROP NOT NULL;

ALTER TABLE brand.brand_partner_link_events
    ADD CONSTRAINT brand_partner_link_events_brand_professional_id_fkey
    FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL NOT VALID;

ALTER TABLE brand.brand_partner_link_events
    ADD CONSTRAINT brand_partner_link_events_affiliate_professional_id_fkey
    FOREIGN KEY (affiliate_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL NOT VALID;

ALTER TABLE brand.brand_partner_link_events
    VALIDATE CONSTRAINT brand_partner_link_events_brand_professional_id_fkey;

ALTER TABLE brand.brand_partner_link_events
    VALIDATE CONSTRAINT brand_partner_link_events_affiliate_professional_id_fkey;

COMMIT;
