-- Item-level impression events (mirrors analytics.section_views' visitor/session/geo
-- columns; swaps section_key grain for item_type/item_id). Written by the item-seen
-- telemetry endpoint; read by analytics:compute-popularity. Dedup is app-side Redis
-- (AnalyticsDedupGuard, 300s) — NOT a DB column, matching section-seen.
-- user_id is nullable here (unlike section_views) to fail-open on the new write path.
-- app_backend bypasses RLS; RLS-on + grants = backend-internal, no anon/authenticated access.
-- Down: DROP TABLE IF EXISTS analytics.item_views;

CREATE TABLE IF NOT EXISTS analytics.item_views (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      uuid,             -- site owner (denormalized; populated by controller, nullable = fail-open)
    site_id      uuid NOT NULL,
    item_type    text NOT NULL,    -- shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
    item_id      text NOT NULL,
    item_title   text,
    section_key  text,
    occurred_at  timestamptz NOT NULL DEFAULT now(),
    session_id   uuid,
    visitor_id   uuid,
    ip_hash      text,
    user_agent   text,
    referrer     text,
    country_code text,
    device_type  text,
    created_at   timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE analytics.item_views IS
  'Item-level impression events for popularity scoring. Mirrors section_views columns; item_type per scored-item taxonomy, item_id = product/item id. Dedup via app-side Redis, not DB.';

CREATE INDEX IF NOT EXISTS item_views_site_occurred_idx
    ON analytics.item_views (site_id, occurred_at);
CREATE INDEX IF NOT EXISTS item_views_site_item_idx
    ON analytics.item_views (site_id, item_type, item_id);

ALTER TABLE analytics.item_views ENABLE ROW LEVEL SECURITY;

GRANT SELECT, INSERT, UPDATE, DELETE ON analytics.item_views TO app_backend;
