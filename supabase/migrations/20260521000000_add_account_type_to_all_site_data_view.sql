-- Add p.account_type AND p.professional_type to the site.all_site_data view.
--
-- Why both:
--   1. account_type — §28.1 introduced this column as the new source of truth
--      for the three account types (brand/partner/individual). StaffSiteController
--      surfaces it on the staff ops dashboard so support can filter by type.
--   2. professional_type — was referenced by StaffSiteController prior to §28.11
--      but never projected by the view, so the JSON was silently emitting null
--      since v2 baseline. Adding it now closes the pre-existing gap and keeps
--      legacy Nightwatch filters working during the dual-write window.
--
-- Pattern: CREATE OR REPLACE VIEW (appends columns at end; preserves dependent
-- objects). Matches v2 baseline's idiom for this view at lines 1706-1753.
-- p.id is already in GROUP BY, so per Postgres functional-dependency rules
-- additional p.* columns project without GROUP BY edits.
--
-- To revert: re-run the view body from
-- supabase/migrations/20260511100000_drop_legacy_schema_bloat.sql lines 29-67.

BEGIN;

CREATE OR REPLACE VIEW site.all_site_data AS
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
    ) FILTER (WHERE b.id IS NOT NULL), '[]'::jsonb) AS blocks,
    -- New columns MUST be appended at end. CREATE OR REPLACE VIEW only allows
    -- adding columns at the tail of the SELECT list; inserting earlier triggers
    -- "cannot change name of view column" — Postgres treats it as a rename.
    p.account_type,
    p.professional_type
FROM site.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN site.themes t ON t.id = s.theme_id
LEFT JOIN site.blocks b ON b.site_id = s.id
GROUP BY s.id, t.id, p.id;

COMMIT;
