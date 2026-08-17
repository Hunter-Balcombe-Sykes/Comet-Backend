-- 20260819000120_content_storefronts_connect_status_check.sql
--
-- Carry site.shop_brands' connect_status vocabulary onto its replacement,
-- BEFORE the DROP takes the original away.
--
-- site.shop_brands has three CHECK constraints; content.storefronts has none.
-- Two of the three are dead and carry nothing:
--
--   shop_brands_selection_mode_check  ('manual' | 'latest')
--   shop_brands_link_mode_check       ('product' | 'checkout')
--
-- Neither column exists on content.storefronts, deliberately: slice 5a fix
-- round 1, Finding 4 established that selection_mode was always the default in
-- practice (ShopContentReader reports the constant 'manual') and that link_mode
-- had already become one global site setting (site.sites.shop_link_mode). There
-- is nothing to enforce.
--
-- The third one is live and is a real guarantee:
--
--   shop_brands_connect_status_check  (NULL | 'pending' | 'failed')
--
-- content.storefronts.connect_status is written by ShopController::addBrand
-- (arming the deferred poll) and by ShopBrandConnectJob's settle and terminal
-- writes, and is READ by connectStatus() to decide 'pending' / 'failed' /
-- 'ready' and by PublicIntegrationConnectionResource, which rejects only
-- 'pending' — so a third value silently reaching the wire is precisely the
-- failure this constraint prevents. 20260813100000 declared the column bare
-- text, so the guarantee did not come across with the data.
--
-- Written now rather than after the DROP because after the DROP there is no
-- original left to compare against, and a lost invariant is not discoverable
-- by reading the replacement.
--
-- NOT VALID is not used: dev holds 15 rows and every one is NULL, so the
-- constraint validates instantly and a full-table check costs nothing here.
--
-- ROLLBACK: ALTER TABLE content.storefronts
--             DROP CONSTRAINT IF EXISTS storefronts_connect_status_check;
BEGIN;

ALTER TABLE content.storefronts
    ADD CONSTRAINT storefronts_connect_status_check
    CHECK (connect_status IS NULL OR connect_status IN ('pending', 'failed'));

COMMIT;
