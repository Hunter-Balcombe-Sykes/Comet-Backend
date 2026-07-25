-- =====================================================================
-- Menu items in MULTIPLE categories (2026-07-21)
-- =====================================================================
-- A dish that appears under several menu sections ("Garlic Bread" in Lunch AND
-- Dinner) used to be stored as one site.menu_items row PER category — the
-- single category_id FK forced it, MenuMerger builds each category
-- independently, and MenuScanApplier created one row per (name, category)
-- miss. Result: the same dish scanned/scraped N times.
--
-- This migration converts the item↔category link to a many-to-many pivot:
--   site.menu_item_categories (menu_item_id, menu_category_id, position)
-- with the display position PER MEMBERSHIP (a dish can sit at slot 2 of
-- Lunch and slot 5 of Dinner). It then MERGES existing same-name duplicates
-- (per menu, normalized-name match, non-manual rows only — owner-authored
-- is_manual dishes are never auto-merged): the oldest row becomes canonical,
-- its duplicates' category memberships and per-platform availability rows are
-- repointed onto it, NULL display fields gap-fill from the duplicates, and the
-- duplicate rows are deleted. Finally the now-redundant single-category
-- columns (category_id, position) are dropped.
--
-- Name normalization matches MenuFetchJob::normalizeName / MenuMerger::norm
-- (lowercase, non-alphanumerics → single space, trim) so "merge" here means
-- the same thing it means at scrape/scan time.
--
-- Backfill-inside-transaction note: mirrors 20260701140100 (same tables) —
-- site.menu_* rows are small per-user sets (hundreds of rows per menu, not a
-- hot commerce table), and the atomicity of backfill + repoint + DROP COLUMN
-- must not depend on CLI internals. lock/statement timeouts bound the risk.

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '60s';

-- ── 1. The pivot ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS site.menu_item_categories (
    menu_item_id     uuid NOT NULL REFERENCES site.menu_items (id) ON DELETE CASCADE,
    menu_category_id uuid NOT NULL REFERENCES site.menu_categories (id) ON DELETE CASCADE,
    position         integer NOT NULL DEFAULT 0,
    created_at       timestamptz,
    updated_at       timestamptz,
    PRIMARY KEY (menu_item_id, menu_category_id)
);
-- Non-CONCURRENTLY is safe here: the table was created in this transaction
-- (empty, no traffic can exist yet).
CREATE INDEX IF NOT EXISTS idx_menu_item_categories_category
    ON site.menu_item_categories (menu_category_id, position);

-- RLS parity with the other five site.menu_* tables (20260702000000).
ALTER TABLE site.menu_item_categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.menu_item_categories FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS menu_item_categories_app_backend_all ON site.menu_item_categories;
CREATE POLICY menu_item_categories_app_backend_all ON site.menu_item_categories
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

-- ── 2. Backfill: every existing row's (category_id, position) becomes a membership ──
INSERT INTO site.menu_item_categories (menu_item_id, menu_category_id, position, created_at, updated_at)
SELECT id, category_id, position, now(), now()
FROM site.menu_items
WHERE category_id IS NOT NULL;

-- ── 3. Merge same-name duplicates (non-manual, per menu) ─────────────────────
-- canonical = the oldest (created_at, id) row per (menu_id, normalized name).
CREATE TEMP TABLE menu_item_dupes ON COMMIT DROP AS
SELECT id AS dupe_id, canonical_id
FROM (
    SELECT id,
           first_value(id) OVER w AS canonical_id,
           row_number()    OVER w AS rn
    FROM site.menu_items
    WHERE is_manual = false
    WINDOW w AS (
        PARTITION BY menu_id, trim(regexp_replace(lower(name), '[^a-z0-9]+', ' ', 'g'))
        ORDER BY created_at NULLS LAST, id
    )
) ranked
WHERE rn > 1;

-- 3a. Repoint the duplicates' category memberships onto the canonical row.
--     DISTINCT ON collapses two dupes sharing a category within this insert;
--     ON CONFLICT skips memberships the canonical already holds.
INSERT INTO site.menu_item_categories (menu_item_id, menu_category_id, position, created_at, updated_at)
SELECT DISTINCT ON (d.canonical_id, mic.menu_category_id)
       d.canonical_id, mic.menu_category_id, mic.position, now(), now()
FROM menu_item_dupes d
JOIN site.menu_item_categories mic ON mic.menu_item_id = d.dupe_id
ORDER BY d.canonical_id, mic.menu_category_id, mic.position
ON CONFLICT (menu_item_id, menu_category_id) DO NOTHING;

-- 3b. Repoint per-platform availability (UNIQUE (menu_item_id, platform) —
--     the canonical's own row wins; a duplicate's row only fills a platform
--     the canonical lacks).
INSERT INTO site.menu_item_platforms (id, menu_item_id, platform, pickup_price, pickup_url, delivery_price, delivery_url, created_at, updated_at)
SELECT DISTINCT ON (d.canonical_id, mip.platform)
       gen_random_uuid(), d.canonical_id, mip.platform,
       mip.pickup_price, mip.pickup_url, mip.delivery_price, mip.delivery_url,
       now(), now()
FROM menu_item_dupes d
JOIN site.menu_item_platforms mip ON mip.menu_item_id = d.dupe_id
ORDER BY d.canonical_id, mip.platform, mip.created_at NULLS LAST
ON CONFLICT (menu_item_id, platform) DO NOTHING;

-- 3c. Gap-fill the canonical's NULL display fields from its duplicates
--     (first non-null by duplicate age). The canonical's own values always win.
UPDATE site.menu_items c
SET description     = COALESCE(c.description,     f.description),
    image_url       = COALESCE(c.image_url,       f.image_url),
    images          = COALESCE(c.images,          f.images),
    rating          = COALESCE(c.rating,          f.rating),
    rating_count    = COALESCE(c.rating_count,    f.rating_count),
    badges          = COALESCE(c.badges,          f.badges),
    base_price      = COALESCE(c.base_price,      f.base_price),
    pickup_price    = COALESCE(c.pickup_price,    f.pickup_price),
    pickup_source   = COALESCE(c.pickup_source,   f.pickup_source),
    delivery_price  = COALESCE(c.delivery_price,  f.delivery_price),
    delivery_source = COALESCE(c.delivery_source, f.delivery_source),
    dd_external_id  = COALESCE(c.dd_external_id,  f.dd_external_id),
    currency        = COALESCE(c.currency,        f.currency)
FROM (
    SELECT d.canonical_id,
           (array_remove(array_agg(mi.description     ORDER BY mi.created_at, mi.id), NULL))[1] AS description,
           (array_remove(array_agg(mi.image_url       ORDER BY mi.created_at, mi.id), NULL))[1] AS image_url,
           (array_remove(array_agg(mi.images          ORDER BY mi.created_at, mi.id), NULL))[1] AS images,
           (array_remove(array_agg(mi.rating          ORDER BY mi.created_at, mi.id), NULL))[1] AS rating,
           (array_remove(array_agg(mi.rating_count    ORDER BY mi.created_at, mi.id), NULL))[1] AS rating_count,
           (array_remove(array_agg(mi.badges          ORDER BY mi.created_at, mi.id), NULL))[1] AS badges,
           (array_remove(array_agg(mi.base_price      ORDER BY mi.created_at, mi.id), NULL))[1] AS base_price,
           (array_remove(array_agg(mi.pickup_price    ORDER BY mi.created_at, mi.id), NULL))[1] AS pickup_price,
           (array_remove(array_agg(mi.pickup_source   ORDER BY mi.created_at, mi.id), NULL))[1] AS pickup_source,
           (array_remove(array_agg(mi.delivery_price  ORDER BY mi.created_at, mi.id), NULL))[1] AS delivery_price,
           (array_remove(array_agg(mi.delivery_source ORDER BY mi.created_at, mi.id), NULL))[1] AS delivery_source,
           (array_remove(array_agg(mi.dd_external_id  ORDER BY mi.created_at, mi.id), NULL))[1] AS dd_external_id,
           (array_remove(array_agg(mi.currency        ORDER BY mi.created_at, mi.id), NULL))[1] AS currency
    FROM menu_item_dupes d
    JOIN site.menu_items mi ON mi.id = d.dupe_id
    GROUP BY d.canonical_id
) f
WHERE c.id = f.canonical_id;

-- 3d. Delete the duplicate rows (their child rows first — explicit, matching
--     the codebase convention of not leaning on FK cascade for cleanups).
DELETE FROM site.menu_item_platforms  WHERE menu_item_id IN (SELECT dupe_id FROM menu_item_dupes);
DELETE FROM site.menu_item_categories WHERE menu_item_id IN (SELECT dupe_id FROM menu_item_dupes);
DELETE FROM site.menu_items           WHERE id           IN (SELECT dupe_id FROM menu_item_dupes);

-- ── 4. Drop the single-category columns ──────────────────────────────────────
-- category_id's FK and idx_menu_items_category go with the column; position now
-- lives per-membership on the pivot.
ALTER TABLE site.menu_items DROP COLUMN IF EXISTS category_id;
ALTER TABLE site.menu_items DROP COLUMN IF EXISTS position;

COMMIT;

-- ROLLBACK (structural only — merged duplicates are not resurrectable):
-- ALTER TABLE site.menu_items ADD COLUMN IF NOT EXISTS category_id uuid;
-- ALTER TABLE site.menu_items ADD COLUMN IF NOT EXISTS position integer NOT NULL DEFAULT 0;
-- UPDATE site.menu_items mi SET category_id = sub.menu_category_id, position = sub.position
--   FROM (
--     SELECT DISTINCT ON (menu_item_id) menu_item_id, menu_category_id, position
--     FROM site.menu_item_categories ORDER BY menu_item_id, position
--   ) sub WHERE sub.menu_item_id = mi.id;
-- DELETE FROM site.menu_items WHERE category_id IS NULL;
-- ALTER TABLE site.menu_items ALTER COLUMN category_id SET NOT NULL;
-- DROP TABLE IF EXISTS site.menu_item_categories;
