-- Drop dead menu_item columns (audit 2026-06-23):
--   modifiers     — the current scrapers (memo23 Uber Eats, dz_omar DoorDash)
--                   expose no item options, so it was written NULL every scrape.
--   is_sold_out   — neither scraper reports it; always false. Never surfaced.
--   ue_external_id — memo23's flattened menu carries no per-item id, so it was
--                   written NULL; nothing ever read it.
-- None are read by the API resource or the dashboard UI. dd_external_id is kept
-- (still populated by DoorDash) intentionally.
ALTER TABLE site.menu_items
    DROP COLUMN IF EXISTS modifiers,
    DROP COLUMN IF EXISTS is_sold_out,
    DROP COLUMN IF EXISTS ue_external_id;
