-- =====================================================================
-- core.users — CHECK constraints for sector / sector_source  (#DINT-1)
-- =====================================================================
-- sector / sector_source were added (20260705..users_sector_columns) as plain
-- nullable text columns with NO DB-level validation; the only guard is
-- UpdateSectorRequest at the SectorController boundary. An out-of-band write
-- (staff tooling, a future job, a raw backfill) can silently persist an
-- off-vocabulary value, after which ProfileDesignPresets::forUser() quietly
-- returns [] and the sitepage simply stops auto-styling — no crash, no error
-- trail. Mirror the sibling architecture_id / skeleton_id CHECK convention to
-- close that gap.
--
-- Allowed `sector` values = App\Services\Profile\SectorTaxonomy::SECTORS slugs.
--   KEEP THIS LIST IN SYNC with that class — adding a sector = a new migration.
-- Allowed `sector_source` values = the three provenance stamps the writers set:
--   'manual'          — SectorController::update()
--   'google-business' — IdentitySync::GOOGLE_SOURCE
--   'instagram'       — InstagramIdentitySync::INSTAGRAM_SOURCE
-- (NOT 'google'/'instagram-only' — verified against the writers + live data.)
--
-- core.users is tiny (<30 rows) and every existing value already conforms, so
-- both the ADD (metadata-only) and the VALIDATE (row scan) are instant here.
-- The NOT VALID + separate-transaction VALIDATE split is nonetheless required by
-- the repo's CI guard (scripts/guard-no-unsafe-migrations.php, Checks 3 & 8),
-- which enforces the safe-by-default shape on core.users regardless of size.

BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE core.users
    ADD CONSTRAINT users_sector_check
    CHECK (sector IS NULL OR sector IN (
        'restaurant', 'cafe', 'bakery', 'bar', 'food-truck', 'caterer',
        'personal-chef', 'hair-salon', 'barber', 'nail-technician', 'makeup-artist', 'esthetician',
        'spa', 'tattoo-artist', 'brows-lashes', 'personal-trainer', 'gym', 'yoga-instructor',
        'nutritionist', 'physiotherapist', 'chiropractor', 'therapist', 'dentist', 'accountant',
        'lawyer', 'financial-advisor', 'consultant', 'real-estate-agent', 'insurance-broker', 'mortgage-broker',
        'marketing-agency', 'it-services', 'virtual-assistant', 'clothing-boutique', 'jewellery', 'florist',
        'gift-shop', 'homewares', 'artisan-maker', 'plumber', 'electrician', 'builder',
        'painter', 'cleaner', 'landscaper', 'handyman', 'removalist', 'pest-control',
        'accommodation', 'event-venue', 'event-planner', 'wedding-planner', 'bartender', 'mechanic',
        'car-detailer', 'auto-electrician', 'tyre-service', 'photographer', 'videographer', 'graphic-designer',
        'artist', 'musician', 'content-creator', 'writer', 'tutor', 'life-coach',
        'music-teacher', 'driving-instructor', 'dance-instructor', 'course-creator', 'other'
    )) NOT VALID;

ALTER TABLE core.users
    ADD CONSTRAINT users_sector_source_check
    CHECK (sector_source IS NULL OR sector_source IN ('manual', 'google-business', 'instagram')) NOT VALID;

COMMIT;

-- Separate transaction (guard Check 8): VALIDATE re-scans existing rows under a
-- SHARE UPDATE EXCLUSIVE lock that does NOT block concurrent writes. Instant on
-- this table (already conforms).
BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE core.users VALIDATE CONSTRAINT users_sector_check;
ALTER TABLE core.users VALIDATE CONSTRAINT users_sector_source_check;

COMMIT;

-- ROLLBACK:
-- ALTER TABLE core.users
--     DROP CONSTRAINT IF EXISTS users_sector_check,
--     DROP CONSTRAINT IF EXISTS users_sector_source_check;
