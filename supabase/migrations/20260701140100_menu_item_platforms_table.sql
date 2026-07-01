-- =====================================================================
-- Menu item per-platform availability → site.menu_item_platforms (FOUND-6)
-- =====================================================================
-- Replaces the menu_items.platforms JSONB array with one row per
-- (menu_item, platform). PER-MODE shape (matches the live writer
-- MenuMerger::platformEntry and reader MenuController::platforms):
--   pickup_price + pickup_url, delivery_price + delivery_url.
-- A mode the store doesn't offer is NULL on both price and url. platform is
-- deliberately un-CHECKed so item availability mirrors any platform without
-- a migration. menu_item_id FK ON DELETE CASCADE so a wholesale item rebuild
-- (MenuFetchJob deletes items each scrape) auto-clears stale child rows.

CREATE TABLE IF NOT EXISTS site.menu_item_platforms (
    id             uuid PRIMARY KEY,
    menu_item_id   uuid NOT NULL REFERENCES site.menu_items (id) ON DELETE CASCADE,
    platform       text NOT NULL,
    pickup_price   numeric(10,2),
    pickup_url     text,
    delivery_price numeric(10,2),
    delivery_url   text,
    created_at     timestamptz,
    updated_at     timestamptz,
    UNIQUE (menu_item_id, platform)
);
CREATE INDEX IF NOT EXISTS idx_menu_item_platforms_item ON site.menu_item_platforms (menu_item_id);

-- Backfill from the JSONB array. Handles BOTH element shapes present in the
-- live dev DB (the plan's "zero rows / legacy shape has no rows" premise was
-- false — 41 real items on one menu use the legacy shape):
--   * per-mode  {platform, pickupPrice, pickupUrl, deliveryPrice, deliveryUrl}
--   * legacy    {platform, price, modes:[...], url}  — one price/url spread
--                across each OFFERED mode (modes ["pickup","delivery"] sets
--                both pickup_* and delivery_*; ["pickup"] leaves delivery NULL).
-- One child row per array element; a mode not offered is NULL price+url.
INSERT INTO site.menu_item_platforms (id, menu_item_id, platform, pickup_price, pickup_url, delivery_price, delivery_url, created_at, updated_at)
SELECT gen_random_uuid(), mi.id,
       e->>'platform',
       CASE WHEN e ? 'pickupPrice'      THEN NULLIF(e->>'pickupPrice', '')::numeric
            WHEN (e->'modes') ? 'pickup' THEN NULLIF(e->>'price', '')::numeric END,
       CASE WHEN e ? 'pickupUrl'         THEN e->>'pickupUrl'
            WHEN (e->'modes') ? 'pickup' THEN e->>'url' END,
       CASE WHEN e ? 'deliveryPrice'       THEN NULLIF(e->>'deliveryPrice', '')::numeric
            WHEN (e->'modes') ? 'delivery' THEN NULLIF(e->>'price', '')::numeric END,
       CASE WHEN e ? 'deliveryUrl'         THEN e->>'deliveryUrl'
            WHEN (e->'modes') ? 'delivery' THEN e->>'url' END,
       now(), now()
FROM site.menu_items mi
CROSS JOIN LATERAL jsonb_array_elements(mi.platforms) AS e
WHERE jsonb_typeof(mi.platforms) = 'array'
  AND e ? 'platform';

ALTER TABLE site.menu_items DROP COLUMN IF EXISTS platforms;

-- ROLLBACK:
-- ALTER TABLE site.menu_items ADD COLUMN IF NOT EXISTS platforms jsonb;
-- UPDATE site.menu_items mi SET platforms = sub.arr
--   FROM (
--     SELECT menu_item_id,
--            jsonb_agg(jsonb_build_object(
--              'platform', platform,
--              'pickupPrice', pickup_price,
--              'pickupUrl', pickup_url,
--              'deliveryPrice', delivery_price,
--              'deliveryUrl', delivery_url
--            )) AS arr
--     FROM site.menu_item_platforms GROUP BY menu_item_id
--   ) sub
--   WHERE sub.menu_item_id = mi.id;
-- DROP TABLE IF EXISTS site.menu_item_platforms;
