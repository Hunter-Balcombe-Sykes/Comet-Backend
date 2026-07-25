-- site.menu_items.images — the dish's full known image set (hero first), JSONB
-- list of URL strings. Additive capture, no backfill (rows fill on each site's
-- next scrape; images[0] always equals image_url when present).
--
-- Per-platform finding (2026-07-17, from each Apify actor's output schema):
-- NEITHER ordering platform exposes more than one image per menu item —
-- Uber Eats (memo23) carries a single per-item `imageUrl`, DoorDash (dz_omar)
-- a single per-item `image_url` (its store-level additional_images are store
-- photos, not item photos). Multiple images therefore only arise CROSS-platform:
-- a dish present on both platforms contributes up to one distinct image from
-- each, ordered by the registry's content priority (Uber Eats first). The
-- column is a list so a future actor exposing real per-item galleries slots in
-- without another migration.
--
-- Down: ALTER TABLE site.menu_items DROP COLUMN IF EXISTS images;

ALTER TABLE site.menu_items
    ADD COLUMN IF NOT EXISTS images jsonb NULL;

COMMENT ON COLUMN site.menu_items.images IS
    'All known image URLs for the dish, hero first (images[0] = image_url). Cross-platform union — no single platform exposes >1 item image today. NULL when the dish has no image.';
