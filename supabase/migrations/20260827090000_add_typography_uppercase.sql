-- Plan 02 (overnight run 2026-08-27), step 3: typography_uppercase returns
-- as a real authored axis.
--
-- The column left 2026-08-06 with the design-kit simplification, but the
-- LOOK never did: apps/pages base.css force-set text-transform: uppercase
-- over the (frontend-only) flag from 2026-08-19. That force line is now
-- gone; the package default flipped to TRUE in the same change, so NULL
-- (every existing row) renders exactly today's all-caps look. Sector
-- presets author it per look (quiet sectors opt out) and the dashboard
-- exposes it as a switch. Nullable, no DB default — NULL means "unset, use
-- the package default", same as every design_kits column; the
-- create_empty_design_kit trigger inserts site_id alone and needs no
-- change.
--
-- ROLLBACK:
--   ALTER TABLE site.design_kits DROP COLUMN IF EXISTS typography_uppercase;

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "site"."design_kits"
    ADD COLUMN IF NOT EXISTS "typography_uppercase" boolean;

COMMIT;
