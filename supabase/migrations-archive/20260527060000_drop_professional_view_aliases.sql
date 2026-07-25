-- ==========================================================================
-- Drop the deferred 'professional_*' view aliases on site.all_site_data
-- and site.public_site_payload (2026-05-26)
--
-- PR #125 kept these column-level aliases as a "FE backwards-compat hatch"
-- pending consumer migration. The cross-repo audit confirmed:
--   - partna-pages + partna-themes: zero refs to professional_id /
--     professional_handle / professional_* anything
--   - Backend: only StaffSiteController reads the professional_* aliases
--     (and was already updated by PR #125 to read $row->user_id, which the
--     view didn't actually expose — silent NULL bug now fixed)
--   - SiteCacheService: reads $row->user_id from site.public_site_payload,
--     which also didn't exist on the view (silent NULL bug now fixed)
--
-- DROP + CREATE because CREATE OR REPLACE can only add columns, not rename
-- or remove them. View dependencies (none here) would block DROP without
-- CASCADE — verified none exist.
--
-- Nested JSON keys inside the blocks aggregate and inside the
-- public_site_payload payload JSONB are deliberately NOT renamed in this
-- migration — those are payload contents, not view aliases, and would
-- need a separate coordinated FE audit if we wanted to flip them too.
-- ==========================================================================

BEGIN;

-- --------------------------------------------------------------------------
-- site.all_site_data
-- --------------------------------------------------------------------------
DROP VIEW site.all_site_data;

CREATE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    s.is_published,
    s.settings AS site_settings,
    s.created_at AS site_created_at,
    s.updated_at AS site_updated_at,
    t.id AS theme_id,
    t.key AS theme_key,
    t.name AS theme_name,
    t.config AS theme_config,
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
    LEFT JOIN site.themes t ON t.id = s.theme_id
    LEFT JOIN site.blocks b ON b.site_id = s.id
GROUP BY s.id, t.id, p.id;

-- Re-grant: DROP VIEW removes grants; CREATE leaves only owner privileges.
-- Match the prior state (app_backend had SELECT/INSERT/UPDATE/DELETE).
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON site.all_site_data TO app_backend';
    END IF;
END;
$$;

-- --------------------------------------------------------------------------
-- site.public_site_payload
-- --------------------------------------------------------------------------
DROP VIEW site.public_site_payload;

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
        'theme', CASE
            WHEN t.id IS NULL THEN NULL::jsonb
            ELSE jsonb_build_object('id', t.id, 'key', t.key, 'name', t.name, 'config', t.config)
        END,
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
    LEFT JOIN site.themes t ON t.id = s.theme_id
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
