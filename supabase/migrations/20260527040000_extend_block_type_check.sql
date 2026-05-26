-- ==========================================================================
-- Extend blocks_block_type_check to include public_contact + workplace
-- (2026-05-26)
--
-- PR #121 added 'public_contact' and 'workplace' to
-- config('partna.section_block_types') but the matching DB CHECK constraint
-- was never updated, so syncAllowedSections fails with SQLSTATE 23514 the
-- first time the dashboard lazy-creates a row for either type.
--
-- Adds the two new types and keeps every existing type. 'link' is retained
-- because it lives in the `links` block_group, not the `sections` group, and
-- the constraint covers both groups.
-- ==========================================================================

BEGIN;

ALTER TABLE site.blocks DROP CONSTRAINT blocks_block_type_check;

ALTER TABLE site.blocks ADD CONSTRAINT blocks_block_type_check
    CHECK (block_type IN (
        'link',
        'gallery',
        'services',
        'booking',
        'contacts_collection',
        'sitepage_analytics',
        'barbershop_info',
        'documents',
        'newsletter',
        'countdown',
        'contact',
        'public_contact',
        'workplace',
        'credentials',
        'experience',
        'bio'
    ));

COMMIT;
