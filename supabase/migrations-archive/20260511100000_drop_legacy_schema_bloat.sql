-- ==========================================================================
-- Drop legacy schema bloat
-- #SCHEMA-2: duplicate index on analytics.link_clicks
-- #SCHEMA-3: orphaned analytics.professional_customer_daily table
-- #SCHEMA-4: dead headshot_*/icon_* columns on core.professionals
-- #SCHEMA-5: dead banner_*/banner_path columns on site.sites
-- ==========================================================================

-- #SCHEMA-2: drop duplicate index on analytics.link_clicks (professional_id, occurred_at)
-- analytics_link_clicks_professional_occurred_idx (keep) and link_clicks_professional_time_idx (drop) are identical.
-- Note: link_clicks_pro_date_range_idx is functionally distinct (DESC + INCLUDE) and is kept.
DROP INDEX IF EXISTS analytics.link_clicks_professional_time_idx;

-- #SCHEMA-3: drop orphaned analytics.professional_customer_daily (missed by 20260506500000_drop_legacy_aggregates.sql)
-- No Eloquent model, no DB::table() references in app/. RLS policies and index drop automatically with the table.
DROP TABLE IF EXISTS analytics.professional_customer_daily;

-- #SCHEMA-4: drop dead headshot_* and icon_* columns on core.professionals
-- These were replaced by the site.site_media pool=design / BrandDesignMediaService pattern.

-- Step 1: rebuild site.all_site_data view without the four dead column aliases.
-- CREATE OR REPLACE VIEW cannot remove columns from an existing view — Postgres
-- treats the column list as a stable schema contract and rejects with
-- SQLSTATE 42P16 (cannot drop columns from view). DROP ... CASCADE first lets
-- us re-shape it; CASCADE handles any downstream dependents (none currently
-- exist, but the clause is safe and future-proof).
DROP VIEW IF EXISTS site.all_site_data CASCADE;

CREATE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.subdomain,
    s.is_published,
    s.settings AS site_settings,
    s.created_at AS site_created_at,
    s.updated_at AS site_updated_at,
    t.id AS theme_id,
    t.key AS theme_key,
    t.name AS theme_name,
    t.config AS theme_config,
    p.id AS professional_id,
    p.handle AS professional_handle,
    p.display_name AS professional_display_name,
    p.bio AS professional_bio,
    p.location_street_address AS professional_location_street_address,
    p.location_city AS professional_location_city,
    p.location_state AS professional_location_state,
    p.location_postcode AS professional_location_postcode,
    p.location_country AS professional_location_country,
    COALESCE(jsonb_agg(
      jsonb_build_object(
        'id', b.id,
        'site_id', b.site_id,
        'professional_id', b.professional_id,
        'block_type', b.block_type,
        'block_group', b.block_group,
        'title', b.title,
        'url', b.url,
        'icon_key', b.icon_key,
        'sort_order', b.sort_order,
        'is_active', b.is_active,
        'settings', b.settings,
        'created_at', b.created_at,
        'updated_at', b.updated_at
      )
      ORDER BY b.sort_order
    ) FILTER (WHERE b.id IS NOT NULL), '[]'::jsonb) AS blocks
FROM site.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN site.themes t ON t.id = s.theme_id
LEFT JOIN site.blocks b ON b.site_id = s.id
GROUP BY s.id, t.id, p.id;

-- Step 2: drop the constraints first, then the columns
ALTER TABLE core.professionals
    DROP CONSTRAINT IF EXISTS professionals_headshot_bucket_when_path,
    DROP CONSTRAINT IF EXISTS professionals_icon_bucket_when_path,
    DROP COLUMN IF EXISTS headshot_bucket,
    DROP COLUMN IF EXISTS headshot_path,
    DROP COLUMN IF EXISTS icon_bucket,
    DROP COLUMN IF EXISTS icon_path;

-- #SCHEMA-5: drop dead banner_bucket and banner_path columns on site.sites
-- Replaced by site.site_media pool=design. No PHP references.
ALTER TABLE site.sites
    DROP CONSTRAINT IF EXISTS sites_banner_bucket_when_path,
    DROP COLUMN IF EXISTS banner_bucket,
    DROP COLUMN IF EXISTS banner_path;
