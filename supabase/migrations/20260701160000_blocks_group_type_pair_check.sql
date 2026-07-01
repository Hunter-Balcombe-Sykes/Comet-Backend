-- ==========================================================================
-- site.blocks: replace the two independent column CHECKs with one pair-CHECK
-- (2026-07-01)
--
-- Today there are TWO independent constraints:
--   link_blocks_block_group_check  CHECK (block_group IN ('links','sections'))   [baseline]
--   blocks_block_type_check        CHECK (block_type IN (16 types))              [20260527040000]
-- Because they are independent, the invalid pair ('sections','link') passes:
-- 'sections' is a valid group AND 'link' is a valid type. Such a row is then
-- invisible to every list endpoint (group/type filters never match it).
--
-- Replace both with a single pair-CHECK enumerating valid (group,type) combos.
-- The 15 section types mirror config('partna.block_types.sections'); 'link' is
-- the sole 'links'-group type. Keep this list in sync with that config map.
--
-- Safe pattern (CONVENTIONS.md §2): DROP old constraints, ADD new one NOT VALID
-- (catalog-only, lock released immediately), then VALIDATE in a separate
-- transaction (SHARE UPDATE EXCLUSIVE — writes continue during validation).
-- Pre-beta the table is empty, so VALIDATE is a no-op scan; the pattern is kept
-- for prod-shape parity.
-- ==========================================================================

BEGIN;
ALTER TABLE site.blocks DROP CONSTRAINT link_blocks_block_group_check;
ALTER TABLE site.blocks DROP CONSTRAINT blocks_block_type_check;
ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
    CHECK (
        (block_group = 'links' AND block_type = 'link')
        OR (block_group = 'sections' AND block_type IN (
            'gallery', 'services', 'booking', 'contacts_collection',
            'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
            'countdown', 'contact', 'public_contact', 'workplace',
            'credentials', 'experience', 'bio'
        ))
    ) NOT VALID;
COMMIT;

-- Validate in a separate transaction (weaker lock; CONVENTIONS.md §2).
BEGIN;
ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;
COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;
-- ALTER TABLE site.blocks ADD CONSTRAINT link_blocks_block_group_check
--     CHECK (block_group = ANY (ARRAY['links', 'sections'])) NOT VALID;
-- ALTER TABLE site.blocks ADD CONSTRAINT blocks_block_type_check
--     CHECK (block_type IN (
--         'link', 'gallery', 'services', 'booking', 'contacts_collection',
--         'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
--         'countdown', 'contact', 'public_contact', 'workplace',
--         'credentials', 'experience', 'bio'
--     )) NOT VALID;
-- COMMIT;
-- BEGIN;
-- ALTER TABLE site.blocks VALIDATE CONSTRAINT link_blocks_block_group_check;
-- ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_block_type_check;
-- COMMIT;
