-- #MIG-4. The legacy alias: platform becomes a DERIVED column, so a raw write
-- to it errors and dual truth is structurally impossible. Only 14 surfaces
-- alias to a non-prefix slug; everything else is the brand prefix.
--
-- GENERATED ALWAYS AS (...) STORED forces a FULL HEAP REWRITE under ACCESS
-- EXCLUSIVE — the value has to be materialised into every tuple. That is
-- unavoidable for STORED (there is no online variant, and VIRTUAL generated
-- columns do not exist before PostgreSQL 18). What CAN be done, and is done
-- here, is: (a) isolate it in its own file so the lock window covers nothing
-- else, and (b) bound it, so a blocked lock aborts the deploy in 2s with a
-- clear error instead of queueing behind live traffic.
--
-- statement_timeout is 60s, not 10s: 10s bounds a catalog write, but this is a
-- rewrite proportional to table size. 60s is the ceiling at which this table is
-- no longer safe to migrate this way at all — if it is ever hit, the answer is a
-- new-table + backfill + swap, not a bigger number.
--
-- Guarded on attgenerated so it is a true no-op on dev, where 20260727110000
-- already performed this swap on 2026-07-27. A blind DROP COLUMN + ADD COLUMN
-- would rewrite dev's table for zero gain and would fail loudly if anything had
-- since come to depend on the column.
--
-- The alias WHEN pairs are pinned pair-for-pair by
-- tests/Unit/Catalog/CatalogLegacyMapTest.php ("matches the generated alias
-- CASE pair-for-pair") against LegacyPlatformMap::specialToLegacyMap().
--
-- Ordering: this DROP COLUMN also drops the three baseline indexes that carried
-- "platform" (idx_platform_connections_unique_active, ..._canonical,
-- ..._user_platform_sort) — PostgreSQL drops any index containing a dropped
-- column. That is why ...110005-110008 create the replacements AFTER this file,
-- and why no explicit DROP INDEX statements are needed anywhere in the family.
--
-- ROLLBACK: not a one-liner, and a FULL HEAP REWRITE under ACCESS EXCLUSIVE
--             twice:
--             ALTER TABLE site.platform_connections DROP COLUMN platform;
--             ALTER TABLE site.platform_connections ADD COLUMN platform text;
--             UPDATE site.platform_connections SET platform = <the CASE in this file>;
--           The per-row VALUES are recoverable -- the CASE is a pure function of
--           surface_key. What is NOT automatic: this file's DROP COLUMN silently
--           took the three baseline indexes carrying `platform` with it
--           (idx_platform_connections_unique_active, ..._canonical,
--           ..._user_platform_sort). Any revert must rebuild them by hand,
--           CONCURRENTLY, one statement per file (CONVENTIONS.md §1).

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '60s';

DO $alias$
BEGIN
    -- Drop only a PLAIN (non-generated) platform column.
    IF EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = '"site"."platform_connections"'::regclass
           AND attname  = 'platform'
           AND NOT attisdropped
           AND attgenerated = ''
    ) THEN
        ALTER TABLE "site"."platform_connections" DROP COLUMN "platform";
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_attribute
         WHERE attrelid = '"site"."platform_connections"'::regclass
           AND attname  = 'platform'
           AND NOT attisdropped
    ) THEN
        ALTER TABLE "site"."platform_connections" ADD COLUMN "platform" text
            GENERATED ALWAYS AS (CASE "surface_key"
                WHEN 'apple_music.artist' THEN 'apple-music'
                WHEN 'apple_podcasts.show' THEN 'apple-podcast'
                WHEN 'bella_booking.book' THEN 'bella-booking'
                WHEN 'google_business.listing' THEN 'google-business'
                WHEN 'ko_fi.page' THEN 'ko-fi'
                WHEN 'resident_advisor.tickets' THEN 'resident-advisor'
                WHEN 'square.order' THEN 'square-ordering'
                WHEN 'youtube_music.channel' THEN 'youtube-music'
                WHEN 'partna.custom_link' THEN 'custom'
                WHEN 'partna.manual_event' THEN 'events-custom'
                WHEN 'partna.storefront' THEN 'shop'
                WHEN 'partna.booking_link' THEN 'booking'
                WHEN 'partna.reserve_link' THEN 'reservations'
                WHEN 'partna.order_link' THEN 'online-ordering'
                ELSE split_part("surface_key", '.', 1)
            END) STORED NOT NULL;
    END IF;
END
$alias$;

COMMIT;
