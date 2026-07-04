-- =====================================================================
-- FOUND-25 — extract the shop brand+product map out of the single
-- site.platform_connections.payload JSONB cell into child tables.
-- The 'shop' connection row stays as the lifecycle/authorization anchor;
-- its payload shrinks to a marker ({"storage":"relational"}).
-- =====================================================================
BEGIN;

CREATE TABLE IF NOT EXISTS site.shop_brands (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    connection_id  uuid NOT NULL REFERENCES site.platform_connections (id) ON DELETE CASCADE,
    brand_id       text NOT NULL,
    provider       text NOT NULL,
    url            text,
    source_url     text,
    name           text,
    currency       text,
    favicon        text,
    logo           text,
    discount_code  text,
    fetch_mode     text,
    is_individual  boolean NOT NULL DEFAULT false,
    position       integer NOT NULL DEFAULT 0,
    -- Style-analysis snapshot for this brand's own storefront URL (feeds
    -- OutsideWebsitesFactor's design-preset vote). Nullable, no code-side
    -- default here — AnalyzeConnectionWebsitesJob fills it lazily, same
    -- contract as the pre-FOUND-25 payload-embedded styleAnalysis.
    style_analysis jsonb,
    created_at     timestamptz DEFAULT now(),
    updated_at     timestamptz DEFAULT now(),
    UNIQUE (connection_id, brand_id)
);
CREATE INDEX IF NOT EXISTS idx_shop_brands_connection ON site.shop_brands (connection_id);

CREATE TABLE IF NOT EXISTS site.shop_products (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_id    uuid NOT NULL REFERENCES site.shop_brands (id) ON DELETE CASCADE,
    product_id  text NOT NULL,
    position    integer NOT NULL DEFAULT 0,
    data        jsonb NOT NULL,
    created_at  timestamptz DEFAULT now(),
    updated_at  timestamptz DEFAULT now(),
    UNIQUE (brand_id, product_id)
);
CREATE INDEX IF NOT EXISTS idx_shop_products_brand ON site.shop_products (brand_id, position);

ALTER TABLE site.shop_brands   ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.shop_brands   FORCE  ROW LEVEL SECURITY;
CREATE POLICY shop_brands_app_backend_all   ON site.shop_brands
    FOR ALL TO app_backend USING (true) WITH CHECK (true);

ALTER TABLE site.shop_products ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.shop_products FORCE  ROW LEVEL SECURITY;
CREATE POLICY shop_products_app_backend_all ON site.shop_products
    FOR ALL TO app_backend USING (true) WITH CHECK (true);

WITH ins_brands AS (
    INSERT INTO site.shop_brands
        (connection_id, brand_id, provider, url, source_url, name, currency,
         favicon, logo, discount_code, fetch_mode, is_individual, position,
         style_analysis, created_at, updated_at)
    SELECT pc.id,
           b.key,
           COALESCE(b.value->>'provider', 'shopify'),
           b.value->>'url',
           b.value->>'sourceUrl',
           b.value->>'name',
           b.value->>'currency',
           b.value->>'favicon',
           b.value->>'logo',
           COALESCE(b.value->>'discountCode', ''),
           b.value->>'fetchMode',
           COALESCE((b.value->>'individual')::boolean, b.key = 'individual'),
           (b.ord - 1)::int,
           b.value->'styleAnalysis',
           now(), now()
    FROM site.platform_connections pc
    CROSS JOIN LATERAL jsonb_each(pc.payload) WITH ORDINALITY AS b(key, value, ord)
    WHERE pc.platform = 'shop'
      AND pc.deleted_at IS NULL
      AND jsonb_typeof(pc.payload) = 'object'
      AND (pc.payload->>'storage') IS DISTINCT FROM 'relational'
      AND jsonb_typeof(b.value) = 'object'
    ON CONFLICT (connection_id, brand_id) DO NOTHING
    RETURNING id AS brand_row_id, connection_id, brand_id
)
INSERT INTO site.shop_products (brand_id, product_id, position, data, created_at, updated_at)
SELECT ib.brand_row_id,
       COALESCE(p.value->>'productId', ''),
       (p.ord - 1)::int,
       p.value,
       now(), now()
FROM ins_brands ib
JOIN site.platform_connections pc ON pc.id = ib.connection_id
CROSS JOIN LATERAL jsonb_array_elements(pc.payload->ib.brand_id->'products') WITH ORDINALITY AS p(value, ord)
WHERE jsonb_typeof(pc.payload->ib.brand_id->'products') = 'array'
ON CONFLICT (brand_id, product_id) DO NOTHING;

UPDATE site.platform_connections
SET payload = '{"storage":"relational"}'::jsonb
WHERE platform = 'shop'
  AND deleted_at IS NULL
  AND (payload->>'storage') IS DISTINCT FROM 'relational';

COMMIT;

-- ROLLBACK (reconstruct payload from child tables, then drop):
-- BEGIN;
-- UPDATE site.platform_connections pc SET payload = COALESCE((
--   SELECT jsonb_object_agg(sb.brand_id, jsonb_strip_nulls(jsonb_build_object(
--            'id', sb.brand_id, 'provider', sb.provider, 'url', sb.url, 'sourceUrl', sb.source_url,
--            'name', sb.name, 'currency', sb.currency, 'favicon', sb.favicon, 'logo', sb.logo,
--            'discountCode', sb.discount_code, 'fetchMode', sb.fetch_mode,
--            'individual', CASE WHEN sb.is_individual THEN true ELSE NULL END,
--            'styleAnalysis', sb.style_analysis,
--            'products', COALESCE((SELECT jsonb_agg(sp.data ORDER BY sp.position)
--                                  FROM site.shop_products sp WHERE sp.brand_id = sb.id), '[]'::jsonb))))
--   FROM site.shop_brands sb WHERE sb.connection_id = pc.id), '{}'::jsonb)
--   WHERE pc.platform = 'shop' AND pc.deleted_at IS NULL;
-- DROP TABLE IF EXISTS site.shop_products;
-- DROP TABLE IF EXISTS site.shop_brands;
-- COMMIT;
