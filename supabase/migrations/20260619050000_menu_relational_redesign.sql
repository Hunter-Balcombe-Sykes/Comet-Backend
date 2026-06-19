-- =====================================================================
-- Menu redesign — relational categories + items (Uber Eats + DoorDash)
-- =====================================================================
-- Supersedes the single `categories` JSONB blob on site.menus. The menu is now
-- three tables so every dish is a real row carrying per-mode prices sourced from
-- the two ordering platforms:
--   site.menus            — one row per user: store-level display + provenance
--   site.menu_categories  — ordered categories
--   site.menu_items       — one row per dish: pickup/delivery prices, a
--                           cross-filled image, DoorDash 👍 rating + badges,
--                           and Uber Eats modifiers.
--
-- Content (structure / description / image / modifiers) prefers Uber Eats; the
-- per-mode prices come from whichever platform the user mapped to pickup vs
-- delivery (the Google-harvested `type` on their online-ordering links). When a
-- matched item exists on both platforms, the Uber Eats image is used and the
-- DoorDash image fills any gap. DoorDash adds a per-item rating + badges Uber
-- Eats doesn't expose. Dashboard-only — never served to the public sitepage.

-- ── site.menus: drop the JSONB blob, add store-level + provenance columns ──
ALTER TABLE site.menus DROP COLUMN IF EXISTS categories;
ALTER TABLE site.menus DROP COLUMN IF EXISTS source;
ALTER TABLE site.menus DROP COLUMN IF EXISTS store_url;

ALTER TABLE site.menus
    ADD COLUMN IF NOT EXISTS content_source    text
        CHECK (content_source IN ('uber-eats', 'doordash')),
    ADD COLUMN IF NOT EXISTS store_name        text,
    ADD COLUMN IF NOT EXISTS logo_url          text,
    ADD COLUMN IF NOT EXISTS pickup_platform   text
        CHECK (pickup_platform IN ('uber-eats', 'doordash')),
    ADD COLUMN IF NOT EXISTS delivery_platform text
        CHECK (delivery_platform IN ('uber-eats', 'doordash')),
    ADD COLUMN IF NOT EXISTS uber_eats_store_url text,
    ADD COLUMN IF NOT EXISTS uber_eats_synced_at timestamptz,
    ADD COLUMN IF NOT EXISTS uber_eats_status    text
        CHECK (uber_eats_status IN ('pending', 'ok', 'unavailable')),
    ADD COLUMN IF NOT EXISTS doordash_store_url  text,
    ADD COLUMN IF NOT EXISTS doordash_synced_at  timestamptz,
    ADD COLUMN IF NOT EXISTS doordash_status     text
        CHECK (doordash_status IN ('pending', 'ok', 'unavailable'));

-- ── site.menu_categories — ordered groups, sourced from the content platform ──
CREATE TABLE IF NOT EXISTS site.menu_categories (
    id              uuid PRIMARY KEY,
    menu_id         uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
    name            text NOT NULL,
    position        integer NOT NULL DEFAULT 0,
    source_platform text CHECK (source_platform IN ('uber-eats', 'doordash')),
    created_at      timestamptz,
    updated_at      timestamptz
);
CREATE INDEX IF NOT EXISTS idx_menu_categories_menu ON site.menu_categories (menu_id, position);

-- ── site.menu_items — one dish per row ──
CREATE TABLE IF NOT EXISTS site.menu_items (
    id              uuid PRIMARY KEY,
    menu_id         uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
    category_id     uuid NOT NULL REFERENCES site.menu_categories (id) ON DELETE CASCADE,
    position        integer NOT NULL DEFAULT 0,
    name            text NOT NULL,
    description     text,
    image_url       text,                                   -- cross-filled (UE preferred, DD fallback)
    modifiers       jsonb,                                  -- Uber Eats option groups
    is_sold_out     boolean NOT NULL DEFAULT false,
    -- DoorDash-only enrichment (Uber Eats exposes neither per item).
    rating          numeric(5,2),                           -- 👍 percent, 0–100
    rating_count    integer,
    badges          jsonb,
    -- Headline price (content platform) + per-mode prices, each from the
    -- platform the user mapped to that mode. NULL when that mode has no platform.
    base_price      numeric(10,2),
    pickup_price    numeric(10,2),
    pickup_source   text CHECK (pickup_source IN ('uber-eats', 'doordash')),
    delivery_price  numeric(10,2),
    delivery_source text CHECK (delivery_source IN ('uber-eats', 'doordash')),
    -- Cross-platform identity for re-matching / future per-item deep links.
    ue_external_id  text,
    dd_external_id  text,
    created_at      timestamptz,
    updated_at      timestamptz
);
CREATE INDEX IF NOT EXISTS idx_menu_items_menu ON site.menu_items (menu_id);
CREATE INDEX IF NOT EXISTS idx_menu_items_category ON site.menu_items (category_id, position);
