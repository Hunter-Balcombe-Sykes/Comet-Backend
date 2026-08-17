-- 20260819000120_content_storefronts_connect_status_check.sql
--
-- Carry site.shop_brands' connect_status vocabulary onto its replacement,
-- BEFORE the DROP takes the original away.
--
-- site.shop_brands has three CHECK constraints; content.storefronts had none.
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
-- Written before the DROP rather than after, because afterwards there is no
-- original left to compare against and a lost invariant is not discoverable by
-- reading the replacement.
--
-- ⚠️ HISTORY — dev ran an EARLIER, UNSAFE FORM of this file: a single
-- `ADD CONSTRAINT ... CHECK (...)` with no NOT VALID, which the pre-push guard
-- rejected (CONVENTIONS.md §2). Dev is NOT re-run: the version is already
-- recorded in supabase_migrations.schema_migrations, and dev already holds the
-- end state this file produces — the constraint present and convalidated, over
-- 15 rows all of which are NULL. What follows is the form PRODUCTION and any
-- from-zero apply will use.
--
-- ROLLBACK: ALTER TABLE content.storefronts
--             DROP CONSTRAINT IF EXISTS storefronts_connect_status_check;

-- Step A — NOT VALID: the catalog write releases its lock immediately and only
-- new rows are checked. Existing rows are not scanned here.
BEGIN;

ALTER TABLE content.storefronts
    ADD CONSTRAINT storefronts_connect_status_check
    CHECK (connect_status IS NULL OR connect_status IN ('pending', 'failed'))
    NOT VALID;

COMMIT;

-- Step B — validate in its OWN transaction. SHARE UPDATE EXCLUSIVE, so reads
-- and writes continue during the scan; bundling this with Step A would hold the
-- heavier lock throughout and defeat the split (guard Check 8).
BEGIN;

ALTER TABLE content.storefronts VALIDATE CONSTRAINT storefronts_connect_status_check;

COMMIT;
