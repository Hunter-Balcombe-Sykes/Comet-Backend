-- Polymorphic popularity ranking per site x content (pages + scored items).
-- Upserted by the analytics:compute-popularity job; read at payload-build time.
-- app_backend bypasses RLS (rolbypassrls=t) so RLS-on + grants is the live-safe
-- posture: backend-internal, anon/authenticated hold no grants -> no access.
-- Down: DROP TABLE IF EXISTS analytics.content_popularity_scores;

CREATE TABLE IF NOT EXISTS analytics.content_popularity_scores (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    site_id      uuid NOT NULL,
    content_type text NOT NULL,   -- page|shop_product|menu_item|menu_category|service|block|gallery_item|engine_item
    content_key  text NOT NULL,   -- page-id for 'page'; item/product id otherwise
    score        double precision NOT NULL,
    rank         integer NOT NULL,
    computed_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT content_popularity_scores_site_type_key_uniq UNIQUE (site_id, content_type, content_key)
);

COMMENT ON TABLE analytics.content_popularity_scores IS
  'Popularity ranks per site x content (pages + scored items). Upserted by analytics:compute-popularity. content_type enumerated in-code (SitepageId + scored-item taxonomy); content_key = page-id for pages, item/product id otherwise.';

CREATE INDEX IF NOT EXISTS content_popularity_scores_site_type_rank_idx
    ON analytics.content_popularity_scores (site_id, content_type, rank);

ALTER TABLE analytics.content_popularity_scores ENABLE ROW LEVEL SECURITY;

GRANT SELECT, INSERT, UPDATE, DELETE ON analytics.content_popularity_scores TO app_backend;
