-- Uber Eats per-item currency + store-level dining modes (additive capture).
-- currency: ISO 4217 code carried per menu item by the Uber Eats scraper
-- (memo23) — DoorDash exposes none, so the column stays NULL for DoorDash-
-- only dishes. dining_modes: the store's supported dining modes from Uber
-- Eats (e.g. ["DELIVERY","PICKUP"]) — display-only; DoorDash leaves it NULL.
-- Both nullable, no backfill needed (net-new capture going forward).

ALTER TABLE site.menu_items
    ADD COLUMN IF NOT EXISTS currency text NULL;

ALTER TABLE site.menus
    ADD COLUMN IF NOT EXISTS dining_modes jsonb NULL;

COMMENT ON COLUMN site.menu_items.currency IS
    'ISO 4217 code from the Uber Eats scrape (per item); NULL for DoorDash-only dishes.';

COMMENT ON COLUMN site.menus.dining_modes IS
    'Store-level supported dining modes from the Uber Eats scrape (e.g. ["DELIVERY","PICKUP"]); NULL when unavailable (DoorDash exposes none).';
