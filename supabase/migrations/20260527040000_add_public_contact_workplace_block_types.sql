-- ==========================================================================
-- Add 'public_contact' and 'workplace' to blocks_block_type_check constraint.
--
-- config/partna.php section_block_types already lists these as valid section
-- types and SectionVisibilityService and the UpsertSectionBlockRequest both
-- accept them — but the DB check constraint hadn't been updated to match.
--
-- The mismatch caused "blocks_block_type_check" CHECK violations when users
-- tried to create a public_contact section block (Sentry issue #118).
-- ==========================================================================

BEGIN;

ALTER TABLE site.blocks DROP CONSTRAINT blocks_block_type_check;

ALTER TABLE site.blocks
    ADD CONSTRAINT blocks_block_type_check
    CHECK (block_type = ANY (ARRAY[
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
    ]::text[]));

COMMIT;
