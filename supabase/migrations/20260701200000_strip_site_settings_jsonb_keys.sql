-- =====================================================================
-- FOUND-16 Phase 2 — strip promoted keys from settings JSONB + re-inject
--                     the columns into both public-read views (LOCKSTEP)
-- =====================================================================
-- GATING: both views emit the whole s.settings blob. Stripping the 10 keys
-- from settings WITHOUT re-injecting the columns into the views would silently
-- drop hero_title/booking_mode/etc. from the public-site payload, the staff
-- view, and the dashboard — a NULL, not an error. The strip and the view
-- CREATE OR REPLACE therefore ship in one atomic migration.
-- =====================================================================
BEGIN;

-- 1. Re-inject the 10 promoted columns into site.all_site_data.site_settings.
--    IMPORTANT (reconciliation directive #1): PR6 lands AFTER PR5, so this body is
--    authored against the POST-PR5 view — PR5 (FOUND-15) already added
--    'platform'/'category'/'live_check_enabled' to the blocks[] objects; they are
--    carried forward VERBATIM here. When writing the real migration, author it
--    against the THEN-CURRENT view (run `\sf site.all_site_data` first) so a
--    concurrent block-key change is never silently dropped. Change vs the post-PR5
--    body: s.settings AS site_settings  ->  the settings merge below.
--    jsonb_strip_nulls drops NULL columns so unset keys stay absent (matching
--    SiteResource's !== null re-merge); booleans (incl. false) are kept.
CREATE OR REPLACE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    s.is_published,
    s.skeleton_id,
    (s.settings || jsonb_strip_nulls(jsonb_build_object(
        'hero_title', s.hero_title,
        'hero_subtitle', s.hero_subtitle,
        'primary_button_text', s.primary_button_text,
        'primary_button_url', s.primary_button_url,
        'bio_text', s.bio_text,
        'show_branding', s.show_branding,
        'charlie_enabled', s.charlie_enabled,
        'services_auto_sync_enabled', s.services_auto_sync_enabled,
        'booking_mode', s.booking_mode,
        'manual_booking_url', s.manual_booking_url
    ))) AS site_settings,
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
                'platform', b.platform,
                'category', b.category,
                'live_check_enabled', b.live_check_enabled,
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

-- 2. Re-inject into site.public_site_payload (payload.site.settings).
--    Authored against the POST-PR5 body (PR5 added the three block keys to the
--    links[] objects — carried forward below). Change vs the post-PR5 body:
--    'settings', s.settings  ->  the settings merge.
CREATE OR REPLACE VIEW site.public_site_payload AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    jsonb_build_object(
        'site', jsonb_build_object(
            'id', s.id,
            'subdomain', s.subdomain,
            'settings', (s.settings || jsonb_strip_nulls(jsonb_build_object(
                'hero_title', s.hero_title,
                'hero_subtitle', s.hero_subtitle,
                'primary_button_text', s.primary_button_text,
                'primary_button_url', s.primary_button_url,
                'bio_text', s.bio_text,
                'show_branding', s.show_branding,
                'charlie_enabled', s.charlie_enabled,
                'services_auto_sync_enabled', s.services_auto_sync_enabled,
                'booking_mode', s.booking_mode,
                'manual_booking_url', s.manual_booking_url
            ))),
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
                    'settings', b.settings,
                    'platform', b.platform,
                    'category', b.category,
                    'live_check_enabled', b.live_check_enabled
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

-- CREATE OR REPLACE VIEW preserves existing grants (unlike DROP+CREATE), so no
-- re-GRANT block is required here. (The 20260527070000 migration re-granted
-- only because it DROPped the views first.)

-- 3. Strip the 10 promoted keys from settings JSONB. Now redundant with the
--    columns; the views re-inject them so consumers see no change.
UPDATE site.sites SET settings = settings
    - 'hero_title'
    - 'hero_subtitle'
    - 'primary_button_text'
    - 'primary_button_url'
    - 'bio_text'
    - 'show_branding'
    - 'charlie_enabled'
    - 'services_auto_sync_enabled'
    - 'booking_mode'
    - 'manual_booking_url'
WHERE settings IS NOT NULL;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- -- 1. Re-inject the 10 keys back into settings JSONB from the columns.
-- UPDATE site.sites SET settings = settings || jsonb_strip_nulls(jsonb_build_object(
--     'hero_title', hero_title,
--     'hero_subtitle', hero_subtitle,
--     'primary_button_text', primary_button_text,
--     'primary_button_url', primary_button_url,
--     'bio_text', bio_text,
--     'show_branding', show_branding,
--     'charlie_enabled', charlie_enabled,
--     'services_auto_sync_enabled', services_auto_sync_enabled,
--     'booking_mode', booking_mode,
--     'manual_booking_url', manual_booking_url
-- )) WHERE settings IS NOT NULL;
-- -- 2. Restore both views to the plain s.settings passthrough but KEEP PR5's three
-- --    block keys (platform/category/live_check_enabled) in blocks[]/links[] — i.e.
-- --    the POST-PR5 view bodies, NOT the original 20260527070000 bodies
-- --    (site_settings = s.settings, payload.site.settings = s.settings).
-- --    CREATE OR REPLACE with those bodies.
-- COMMIT;
