-- =====================================================================
-- Skeleton system cleanup migration
-- =====================================================================
-- Phase 2 of the skeleton-system rollout. Replaces the per-site `theme_id`
-- FK + `site.themes` catalog with a code-side enum `skeleton_id` + a per-
-- site `site.design_kits` table (column-per-var, all NULLABLE).
--
-- Spec: ../../../docs/superpowers/specs/2026-05-26-skeleton-system-design.md §8.1
-- Plan: ../../../docs/superpowers/plans/2026-05-26-skeleton-system.md Phase 2
--
-- DDL outline:
--   1. Drop both dependent views (site.all_site_data, site.public_site_payload)
--      — they reference s.theme_id and JOIN site.themes, so they have to go
--      before the column can be dropped. They're recreated at the end of this
--      migration without any theme references.
--   2. Replace site.sites.theme_id FK with site.sites.skeleton_id TEXT enum.
--   3. Drop the set_default_theme_for_site() function (CASCADE drops the trigger).
--   4. Drop the site.themes table (CASCADE drops the FK constraint).
--   5. Strip the legacy settings.design.* JSONB sub-key from every site.
--   6. Create the site.design_kits table (FK only — no var columns yet;
--      columns are added incrementally per layer-sweep step 4).
--   7. Auto-create an empty design_kits row when a new site is inserted.
--   8. Recreate the two views without any theme columns / JOIN.
-- =====================================================================

-- Atomic: every statement below is transaction-safe (no CONCURRENTLY, no
-- ALTER TYPE ADD VALUE), so wrapping the whole sequence in BEGIN/COMMIT means
-- a mid-migration failure rolls back to the pre-migration schema instead of
-- leaving the public-site views / theme_id column / site.themes table dropped
-- with no clean recovery path.
BEGIN;

-- 1. Drop the two views that depend on site.sites.theme_id and site.themes.
--    They're recreated at the bottom of this migration without those refs.
DROP VIEW IF EXISTS site.all_site_data;
DROP VIEW IF EXISTS site.public_site_payload;

-- 2. Replace theme_id FK with skeleton_id TEXT enum.
ALTER TABLE site.sites
  DROP COLUMN theme_id,
  ADD COLUMN skeleton_id TEXT NOT NULL
    DEFAULT 'skeleton-1'
    CHECK (skeleton_id IN ('skeleton-1','skeleton-2','skeleton-3','skeleton-4'));

-- 3. Drop the default-assignment Postgres function. CASCADE removes the
--    BEFORE INSERT trigger on site.sites that called it.
DROP FUNCTION IF EXISTS set_default_theme_for_site CASCADE;

-- 4. Drop the themes catalog table outright. CASCADE removes any remaining
--    dependencies (indexes, the now-orphaned sites_theme_fk if it still
--    lingers, etc.).
--
-- Rehearsed against development 2026-06-03. site.themes confirmed empty
-- (0 rows) before drop. Rollback: restore from backup (DROP TABLE/COLUMN
-- are irreversible).
DROP TABLE IF EXISTS site.themes CASCADE;

-- 5. Strip the legacy `settings.design.*` JSONB sub-key from every site row.
--    The new design vars live in their own table (site.design_kits) instead
--    of inside settings JSONB. This is a one-shot scrub; no future code
--    writes back to settings.design.
UPDATE site.sites SET settings = settings - 'design' WHERE settings ? 'design';

-- 6. Create the design_kits table. One row per site, PK = site_id, FK with
--    ON DELETE CASCADE so removing a site cleans up its kit. NO design var
--    columns exist yet — columns are added incrementally per layer-sweep
--    step 4 as design kit vars are introduced.
CREATE TABLE site.design_kits (
  site_id UUID PRIMARY KEY REFERENCES site.sites(id) ON DELETE CASCADE
);

-- 7. Auto-create an empty design_kits row whenever a site is created. The
--    trigger fires on every INSERT into site.sites; existing sites are
--    backfilled separately (see Phase 2 step 2.4 in the plan — not in the
--    migration so the backfill window stays predictable).
CREATE OR REPLACE FUNCTION site.create_empty_design_kit()
RETURNS TRIGGER AS $$
BEGIN
  INSERT INTO site.design_kits (site_id) VALUES (NEW.id);
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_create_empty_design_kit ON site.sites;
CREATE TRIGGER trg_create_empty_design_kit
  AFTER INSERT ON site.sites
  FOR EACH ROW EXECUTE FUNCTION site.create_empty_design_kit();

-- =====================================================================
-- 8. Recreate the two views without theme references.
-- =====================================================================

-- site.all_site_data — staff ops view. Drops theme_id/theme_key/theme_name/
-- theme_config columns and adds skeleton_id.
CREATE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    s.is_published,
    s.skeleton_id,
    s.settings AS site_settings,
    s.created_at AS site_created_at,
    s.updated_at AS site_updated_at,
    p.handle,
    p.display_name,
    p.bio,
    p.location_street_address,
    p.location_city,
    p.location_state,
    p.location_postcode,
    p.location_country,
    COALESCE(
        jsonb_agg(
            jsonb_build_object(
                'id', b.id,
                'site_id', b.site_id,
                'user_id', b.user_id,
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
        ) FILTER (WHERE b.id IS NOT NULL),
        '[]'::jsonb
    ) AS blocks,
    p.account_type
FROM site.sites s
    JOIN core.users p ON p.id = s.user_id
    LEFT JOIN site.blocks b ON b.site_id = s.id
GROUP BY s.id, p.id;

-- Re-grant: DROP VIEW removes grants; CREATE leaves only owner privileges.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON site.all_site_data TO app_backend';
    END IF;
END;
$$;

-- site.public_site_payload — drops the theme JSON key + theme JOIN, adds
-- skeleton_id at the top level of the payload (replacing the `theme` object).
CREATE VIEW site.public_site_payload AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    jsonb_build_object(
        'site', jsonb_build_object(
            'id', s.id,
            'subdomain', s.subdomain,
            'settings', s.settings,
            'is_published', s.is_published,
            'skeleton_id', s.skeleton_id,
            'gallery', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'webp'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'gallery'::text
                    AND sm.media_type::text = 'image'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'content_images', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'webp'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'content'::text
                    AND sm.media_type::text = 'image'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'gallery_videos', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'media_type', sm.media_type,
                        'processing_state', sm.processing_state,
                        'duration_ms', sm.duration_ms,
                        'poster', sm.poster_path,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'mp4'::text
                        ), '{}'::jsonb),
                        'streams', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'hls_playlist'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'gallery'::text
                    AND sm.media_type::text = 'video'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'content_videos', COALESCE((
                SELECT jsonb_agg(
                    jsonb_build_object(
                        'id', sm.id,
                        'alt_text', sm.alt_text,
                        'caption', sm.caption,
                        'sort_order', sm.sort_order,
                        'media_type', sm.media_type,
                        'processing_state', sm.processing_state,
                        'duration_ms', sm.duration_ms,
                        'poster', sm.poster_path,
                        'variants', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'mp4'::text
                        ), '{}'::jsonb),
                        'streams', COALESCE((
                            SELECT jsonb_object_agg(mv.variant_key, mv.path)
                            FROM site.media_variants mv
                            WHERE mv.media_id = sm.id AND mv.artifact_type::text = 'hls_playlist'::text
                        ), '{}'::jsonb)
                    )
                    ORDER BY sm.sort_order, sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'content'::text
                    AND sm.media_type::text = 'video'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
            ), '[]'::jsonb),
            'document', (
                SELECT jsonb_build_object(
                    'id', sm.id,
                    'title', sm.alt_text,
                    'caption', sm.caption,
                    'original_mime', sm.original_mime,
                    'original_size_bytes', sm.original_size_bytes,
                    'original_filename', sm.original_filename,
                    'preview_url', sm.path,
                    'created_at', sm.created_at
                )
                FROM site.site_media sm
                WHERE sm.site_id = s.id
                    AND sm.pool::text = 'documents'::text
                    AND sm.media_type::text = 'document'::text
                    AND sm.deleted_at IS NULL
                    AND sm.is_active = true
                LIMIT 1
            )
        ),
        'professional', jsonb_build_object(
            'id', p.id,
            'handle', p.handle,
            'display_name', p.display_name,
            'bio', p.bio,
            'country_code', p.country_code,
            'timezone', p.timezone,
            'public_contact_number', p.public_contact_number,
            'public_contact_email', p.public_contact_email
        ),
        -- Skeleton id replaces the old per-site theme object. partna-pages
        -- merges the per-user design_kit (separate table) with code-side
        -- defaults at read time; this view exposes the skeleton choice only.
        'skeleton_id', s.skeleton_id,
        'links', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', b.id,
                    'block_type', b.block_type,
                    'title', b.title,
                    'url', b.url,
                    'icon_key', b.icon_key,
                    'sort_order', b.sort_order,
                    'settings', b.settings
                )
                ORDER BY b.sort_order, b.created_at
            )
            FROM site.blocks b
            WHERE b.site_id = s.id
                AND b.block_group = 'links'::text
                AND b.is_active = true
                AND b.deleted_at IS NULL
        ), '[]'::jsonb),
        'sections', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', b.id,
                    'block_type', b.block_type,
                    'title', b.title,
                    'url', b.url,
                    'icon_key', b.icon_key,
                    'sort_order', b.sort_order,
                    'is_enabled', b.is_enabled,
                    'is_active', b.is_active,
                    'settings', b.settings
                )
                ORDER BY b.sort_order, b.created_at
            )
            FROM site.blocks b
            WHERE b.site_id = s.id
                AND b.block_group = 'sections'::text
                AND b.is_enabled = true
                AND b.is_active = true
                AND b.deleted_at IS NULL
        ), '[]'::jsonb),
        'services', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', sv.id,
                    'title', sv.title,
                    'description', sv.description,
                    'price_cents', sv.price_cents,
                    'currency_code', sv.currency_code,
                    'duration_minutes', sv.duration_minutes,
                    'is_active', sv.is_active,
                    'sort_order', sv.sort_order,
                    'category', COALESCE(sc.title, 'Services'::text)
                )
                ORDER BY (COALESCE(sc.sort_order, 2147483647)),
                         (lower(COALESCE(sc.title, 'Services'::text))),
                         sv.sort_order,
                         sv.created_at
            )
            FROM site.services sv
                LEFT JOIN site.service_categories sc ON sc.id = sv.category_id AND sc.deleted_at IS NULL
            WHERE sv.user_id = p.id
                AND sv.is_active = true
                AND sv.deleted_at IS NULL
        ), '[]'::jsonb)
    ) AS payload
FROM site.sites s
    JOIN core.users p ON p.id = s.user_id
WHERE s.is_published = true
    AND p.status = 'active'::text
    AND p.deleted_at IS NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON site.public_site_payload TO app_backend';
    END IF;
END;
$$;

COMMIT;
