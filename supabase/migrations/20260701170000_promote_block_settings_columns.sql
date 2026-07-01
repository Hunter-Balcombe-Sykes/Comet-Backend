-- =====================================================================
-- FOUND-15 (expand) — promote site.blocks.settings query-predicate keys to columns
-- =====================================================================
-- Adds live_check_enabled / category / platform as real columns on
-- site.blocks, backfills them from the settings JSONB (links blocks only),
-- adds a category CHECK + a partial index on the live_check_enabled column.
--
-- This is the EXPAND half of an expand/contract pair. It does NOT strip the
-- settings keys and does NOT touch the public-read views — the keys remain in
-- JSONB so the views, LiveStatusInjector, LinkBlockResource and getLinks keep
-- working unchanged while code dual-writes. The contract half
-- (20260701000100_strip_block_settings_keys_and_views.sql) strips the keys and
-- rewrites both views once all readers + both frontends are on the columns.
--
-- Pre-beta: site.blocks has zero rows, so the backfill is a no-op now; it is
-- written for prod-shape parity (idempotent, links-scoped).
-- =====================================================================
BEGIN;

ALTER TABLE site.blocks
    ADD COLUMN live_check_enabled boolean NOT NULL DEFAULT false,
    ADD COLUMN category           text,
    ADD COLUMN platform           text;

-- Backfill from JSONB, LINKS ONLY. block_group='sections' rows (e.g. booking
-- sections) keep their own settings.platform untouched — that is a different
-- field, read by SitepageDataResolverService::getBooking.
UPDATE site.blocks
SET live_check_enabled = COALESCE(NULLIF(settings->>'live_check_enabled', '')::boolean, false),
    category           = NULLIF(settings->>'category', ''),
    platform           = NULLIF(settings->>'platform', '')
WHERE block_group = 'links';

-- category CHECK. Enum mirrors config('partna.link_categories'); keep the two
-- in lockstep (adding a category = update this CHECK + the config array).
-- NOT VALID -> VALIDATE so a non-empty prod table is checked without a long lock.
ALTER TABLE site.blocks
    ADD CONSTRAINT blocks_category_check
    CHECK (category IS NULL OR category IN
        ('social','booking','education','content','events','streaming','other'))
    NOT VALID;
ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_category_check;

COMMIT;

-- The partial index on live_check_enabled is created CONCURRENTLY in
-- 20260701170001_promote_block_settings_indexes.sql (CONCURRENTLY cannot run
-- inside a transaction block — see supabase/migrations/CONVENTIONS.md §1).

-- ROLLBACK:
-- BEGIN;
-- DROP INDEX IF EXISTS site.idx_blocks_live_check_enabled_active;
-- ALTER TABLE site.blocks DROP CONSTRAINT IF EXISTS blocks_category_check;
-- ALTER TABLE site.blocks
--     DROP COLUMN IF EXISTS platform,
--     DROP COLUMN IF EXISTS category,
--     DROP COLUMN IF EXISTS live_check_enabled;
-- COMMIT;
