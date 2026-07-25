-- Human-readable URL slugs for public sitepage detail items (events + menu
-- items). Replaces machine-id detail URLs (events' SHA1 hex, menu items' UUID)
-- with name-derived slugs carrying -2/-3 collision suffixes; is_current=false
-- rows are retired slugs kept alive as 301 redirect targets so old shared links
-- never break. Per-profile registry (uniqueness scoped to user_id — two
-- professionals can each own 'opening-night'). See
-- docs/superpowers/specs/2026-07-24-sitepage-item-url-slugs-design.md
-- (spec lives in the partna-monorepo repo).
--
-- Owned + written exclusively by App\Services\Site\ItemSlugAllocator (via the
-- MenuItem observer, the platform-sync writer, and the slugs:backfill command).
-- Read by PublicMenuController + PublicIntegrationConnectionResource to emit
-- `slug` + `aliases` on the public payload. item_key holds the menu item's
-- UUID or the event's 16-char SHA1 hex (EventsPayload::id) — text, not an FK,
-- because it spans two entity kinds (one of which has no table row).
--
-- FK + CHECK are added INLINE, not via the NOT VALID -> VALIDATE split: this
-- table is created empty in this transaction, so there is no existing data to
-- validate and no concurrent writer yet (same reasoning as
-- 20260723090000_create_action_events.sql; CONVENTIONS.md §2/§4 govern
-- retrofits onto populated tables). Indexes are plain (non-CONCURRENTLY) for
-- the same born-empty reason.
--
-- No explicit GRANT/RLS: the baseline's `ALTER DEFAULT PRIVILEGES IN SCHEMA
-- site ... TO app_backend` already covers DML, and withholding any anon/
-- authenticated table grant keeps it internal — matching its sibling tables
-- site.menu_items and site.platform_connections.
--
-- Down: DROP TABLE IF EXISTS site.item_slugs;

CREATE TABLE IF NOT EXISTS site.item_slugs (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id    uuid NOT NULL,
    item_type  text NOT NULL,
    item_key   text NOT NULL,   -- menu item UUID, or event SHA1 hex (EventsPayload::id)
    slug       text NOT NULL,
    is_current boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT item_slugs_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT item_slugs_type_check CHECK (item_type IN ('event', 'menu_item'))
);

COMMENT ON TABLE site.item_slugs IS
  'Per-profile human-readable URL slug registry for public sitepage detail items (events + menu items). is_current=false rows are retired slugs kept as 301 redirect targets. Owned by App\Services\Site\ItemSlugAllocator.';

-- No two live items in one profile can share a slug — drives the allocator's
-- insert-and-catch collision loop (fish-tacos, fish-tacos-2, ...).
CREATE UNIQUE INDEX IF NOT EXISTS item_slugs_unique_slug
    ON site.item_slugs (user_id, slug);

-- Exactly one current slug per item.
CREATE UNIQUE INDEX IF NOT EXISTS item_slugs_one_current
    ON site.item_slugs (user_id, item_type, item_key)
    WHERE is_current;

-- Batch-load an item's slug history (current + retired) when building payloads.
CREATE INDEX IF NOT EXISTS item_slugs_lookup
    ON site.item_slugs (user_id, item_type, item_key);
