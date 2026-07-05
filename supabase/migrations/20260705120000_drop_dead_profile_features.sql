-- =====================================================================
-- Delete dead profile features — bio/hero/CTA, credentials, experience,
-- countdown, sitepage_analytics (schema cleanup, applied LAST)
-- =====================================================================
-- Companion to the PHP/config removal (Tasks 1-10 of the
-- docs/superpowers/plans/2026-07-05-delete-dead-profile-features.md plan).
-- Apply to dev ONLY AFTER that code is deployed — both public-read views and
-- the currently-deployed code select these columns until then.
--
-- Order: delete dead-block-type rows (so the narrowed CHECK validates) ->
-- recreate the two views without the dead refs -> trim the block-type CHECK ->
-- drop the columns -> drop the child tables.
--
-- NOTE ON THE VIEW REWRITE: site.all_site_data's settings merge ALSO carries
-- the 5 dead hero/bio keys (identical to public_site_payload's), even though
-- they're not surfaced as their own top-level columns there. They must be
-- stripped from BOTH views' jsonb_build_object calls, not just from
-- public_site_payload — otherwise `DROP COLUMN` on site.sites below fails
-- with a dependent-view error (all_site_data would still reference
-- s.hero_title etc.).
-- =====================================================================
BEGIN;

-- 1. Delete rows of the 5 dead block types so the narrowed CHECK (step 3)
--    validates cleanly.
DELETE FROM site.blocks
WHERE block_type IN ('bio', 'credentials', 'experience', 'countdown', 'sitepage_analytics');

-- 2. Recreate the two views without the dead references. all_site_data
--    exposes `bio` as a top-level column, so its signature changes -> DROP +
--    CREATE (not CREATE OR REPLACE). public_site_payload's signature is
--    unchanged (JSONB-internal keys only) but recreated the same way for
--    clarity. Bodies are copied verbatim from 20260701200000, minus the dead
--    refs.
DROP VIEW IF EXISTS site.public_site_payload;
DROP VIEW IF EXISTS site.all_site_data;

CREATE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    s.is_published,
    s.skeleton_id,
    (s.settings || jsonb_strip_nulls(jsonb_build_object(
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

CREATE VIEW site.public_site_payload AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    jsonb_build_object(
        'site', jsonb_build_object(
            'id', s.id,
            'subdomain', s.subdomain,
            'settings', (s.settings || jsonb_strip_nulls(jsonb_build_object(
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

-- Re-grant: DROP VIEW removes grants; CREATE leaves only owner privileges.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON site.all_site_data TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON site.public_site_payload TO app_backend';
    END IF;
END;
$$;

-- 3. Trim the block-type CHECK to the 10 surviving section types.
ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;

ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
    CHECK (
        (block_group = 'links' AND block_type = 'link')
        OR (block_group = 'sections' AND block_type IN (
            'gallery', 'services', 'booking', 'contacts_collection',
            'barbershop_info', 'documents', 'newsletter',
            'contact', 'public_contact', 'workplace'
        ))
    ) NOT VALID;

ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;

-- 4. Drop the dead columns.
ALTER TABLE site.sites
    DROP COLUMN IF EXISTS hero_title,
    DROP COLUMN IF EXISTS hero_subtitle,
    DROP COLUMN IF EXISTS primary_button_text,
    DROP COLUMN IF EXISTS primary_button_url,
    DROP COLUMN IF EXISTS bio_text;

ALTER TABLE core.users DROP COLUMN IF EXISTS bio;

-- 5. Drop the child tables (FKs are ON DELETE CASCADE; no view/function
--    depends on them per grep of supabase/migrations/*.sql).
DROP TABLE IF EXISTS core.user_credentials;
DROP TABLE IF EXISTS core.user_experience;

COMMIT;

-- ROLLBACK (data is unrecoverable — the deleted blocks/columns/tables were
-- dead weight before the drop; this restores STRUCTURE only):
-- BEGIN;
-- ALTER TABLE site.sites
--     ADD COLUMN hero_title text,
--     ADD COLUMN hero_subtitle text,
--     ADD COLUMN primary_button_text text,
--     ADD COLUMN primary_button_url text,
--     ADD COLUMN bio_text text;
-- ALTER TABLE core.users ADD COLUMN bio text;
-- CREATE TABLE core.user_credentials (
--     id          uuid        PRIMARY KEY,
--     user_id     uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
--     title       text        NOT NULL,
--     issuer      text,
--     year        text,
--     description text,
--     sort_order  integer     NOT NULL DEFAULT 0,
--     created_at  timestamptz,
--     updated_at  timestamptz
-- );
-- CREATE TABLE core.user_experience (
--     id           uuid        PRIMARY KEY,
--     user_id      uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
--     role         text        NOT NULL,
--     organisation text,
--     start_year   text,
--     end_year     text,
--     description  text,
--     sort_order   integer     NOT NULL DEFAULT 0,
--     created_at   timestamptz,
--     updated_at   timestamptz
-- );
-- ALTER TABLE site.blocks DROP CONSTRAINT blocks_group_type_check;
-- ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
--     CHECK (
--         (block_group = 'links' AND block_type = 'link')
--         OR (block_group = 'sections' AND block_type IN (
--             'gallery', 'services', 'booking', 'contacts_collection',
--             'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
--             'countdown', 'contact', 'public_contact', 'workplace',
--             'credentials', 'experience', 'bio'
--         ))
--     ) NOT VALID;
-- ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;
-- -- Views: restore the pre-cleanup bodies from 20260701200000_strip_site_settings_jsonb_keys.sql.
-- COMMIT;
