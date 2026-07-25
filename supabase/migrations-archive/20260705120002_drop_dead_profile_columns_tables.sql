-- =====================================================================
-- Drop dead profile columns/tables (split out of 20260705120000, MIG-1)
-- =====================================================================
-- Runs last in the 3-file split: 20260705120000 already rebuilt
-- site.all_site_data and site.public_site_payload without these columns,
-- so no view depends on them by the time this file runs, and DROP COLUMN /
-- DROP TABLE below cannot hit a dependent-view error.
-- =====================================================================
BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

-- Drop the dead columns.
ALTER TABLE site.sites
    DROP COLUMN IF EXISTS hero_title,
    DROP COLUMN IF EXISTS hero_subtitle,
    DROP COLUMN IF EXISTS primary_button_text,
    DROP COLUMN IF EXISTS primary_button_url,
    DROP COLUMN IF EXISTS bio_text;

ALTER TABLE core.users DROP COLUMN IF EXISTS bio;

-- Drop the child tables (FKs are ON DELETE CASCADE; no view/function
-- depends on them per grep of supabase/migrations/*.sql).
DROP TABLE IF EXISTS core.user_credentials;
DROP TABLE IF EXISTS core.user_experience;

COMMIT;

-- ROLLBACK (data is unrecoverable — the deleted blocks/columns/tables were
-- dead weight before the drop; this restores STRUCTURE only):
-- BEGIN;
-- ALTER TABLE site.sites
--     ADD COLUMN hero_title text,
--     ADD COLUMN hero_subtitle text,
--     ADD COLUMN primary_button_text text,
--     ADD COLUMN primary_button_url text,
--     ADD COLUMN bio_text text;
-- ALTER TABLE core.users ADD COLUMN bio text;
-- CREATE TABLE core.user_credentials (
--     id          uuid        PRIMARY KEY,
--     user_id     uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
--     title       text        NOT NULL,
--     issuer      text,
--     year        text,
--     description text,
--     sort_order  integer     NOT NULL DEFAULT 0,
--     created_at  timestamptz,
--     updated_at  timestamptz
-- );
-- CREATE TABLE core.user_experience (
--     id           uuid        PRIMARY KEY,
--     user_id      uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
--     role         text        NOT NULL,
--     organisation text,
--     start_year   text,
--     end_year     text,
--     description  text,
--     sort_order   integer     NOT NULL DEFAULT 0,
--     created_at   timestamptz,
--     updated_at   timestamptz
-- );
-- ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;
-- ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
--     CHECK (
--         (block_group = 'links' AND block_type = 'link')
--         OR (block_group = 'sections' AND block_type IN (
--             'gallery', 'services', 'booking', 'contacts_collection',
--             'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
--             'countdown', 'contact', 'public_contact', 'workplace',
--             'credentials', 'experience', 'bio'
--         ))
--     ) NOT VALID;
-- ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;
-- -- Views: restore the pre-cleanup bodies from 20260701200000_strip_site_settings_jsonb_keys.sql.
-- COMMIT;
