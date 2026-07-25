-- =====================================================================
-- Services in MULTIPLE categories (2026-07-21)
-- =====================================================================
-- Companion to the menu multi-category rework (20260721090000) and the Fresha
-- projection (20260721150000): a service can now belong to several categories
-- via the site.service_category_assignments pivot. The single category_id FK
-- (composite to service_categories(id, user_id), ON DELETE SET NULL) is
-- backfilled into the pivot and dropped.
--
-- Ordering is unchanged: services.sort_order stays the GLOBAL per-user order
-- (the partial unique services_professional_sort_order_uq is untouched); the
-- grouped display keeps ordering by category sort_order then service
-- sort_order, so the pivot carries NO position column. Deleting a category
-- detaches its members; a service with zero memberships is "Uncategorised"
-- (today's ON DELETE SET NULL semantic, expressed as no-rows).
--
-- Fresha projection: a serviceId listed under several Fresha categories now
-- projects ONE service row with a membership per label (the dedup previously
-- kept only the first label).

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '30s';

CREATE TABLE IF NOT EXISTS site.service_category_assignments (
    service_id          uuid NOT NULL REFERENCES site.services (id) ON DELETE CASCADE,
    service_category_id uuid NOT NULL REFERENCES site.service_categories (id) ON DELETE CASCADE,
    created_at          timestamptz,
    updated_at          timestamptz,
    PRIMARY KEY (service_id, service_category_id)
);
-- Safe non-CONCURRENTLY: created in this transaction, no traffic yet.
CREATE INDEX IF NOT EXISTS idx_service_category_assignments_category
    ON site.service_category_assignments (service_category_id);

-- RLS parity with the services tables.
ALTER TABLE site.service_category_assignments ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.service_category_assignments FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS service_category_assignments_app_backend_all ON site.service_category_assignments;
CREATE POLICY service_category_assignments_app_backend_all ON site.service_category_assignments
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

-- Backfill every existing assignment (live AND trashed services — a restored
-- service must come back into its category).
INSERT INTO site.service_category_assignments (service_id, service_category_id, created_at, updated_at)
SELECT id, category_id, now(), now()
FROM site.services
WHERE category_id IS NOT NULL;

-- ── Rebuild site.public_site_payload BEFORE dropping category_id ─────────────
-- The view's services subquery joined service_categories via the single
-- category_id (so the column drop below would be blocked by the dependency).
-- Body copied from 20260718200000_pre_account_sites.sql (the latest full
-- definition) with exactly two changes, both in the services subquery:
--   1. the category join goes through the pivot (first membership by category
--      sort_order/title — output shape + ordering identical), and
--   2. `sv.source IS NULL` keeps Fresha projections (20260721150000) out of
--      the public services payload, mirroring the Laravel-side readers.
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
                'show_branding', s.show_branding,
                'charlie_enabled', s.charlie_enabled,
                'services_auto_sync_enabled', s.services_auto_sync_enabled,
                'booking_mode', s.booking_mode,
                'manual_booking_url', s.manual_booking_url
            ))),
            'is_published', s.is_published,
            'skeleton_id', s.architecture_id,
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
        'skeleton_id', s.architecture_id,
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
                -- Multi-category (20260721180000): the display category is the
                -- FIRST membership (category sort_order, then title) via the
                -- pivot — output shape and ordering identical to the old
                -- single category_id join.
                LEFT JOIN LATERAL (
                    SELECT c.title, c.sort_order
                    FROM site.service_category_assignments a
                        JOIN site.service_categories c
                            ON c.id = a.service_category_id AND c.deleted_at IS NULL
                    WHERE a.service_id = sv.id
                    ORDER BY c.sort_order, lower(c.title)
                    LIMIT 1
                ) sc ON true
            WHERE sv.user_id = p.id
                -- Manual services only — Fresha projections (source='fresha',
                -- 20260721150000) render via the booking selection blob, never
                -- the public services section (mirrors the Laravel readers).
                AND sv.source IS NULL
                AND sv.is_active = true
                AND sv.deleted_at IS NULL
        ), '[]'::jsonb)
    ) AS payload
FROM site.sites s
    JOIN core.users p ON p.id = s.user_id
WHERE s.is_published = true
    AND p.status IN ('active', 'unclaimed')
    AND p.deleted_at IS NULL;

-- Re-grant: CREATE OR REPLACE VIEW preserves existing grants when the output
-- column signature is unchanged (unlike DROP+CREATE), but re-stating this is
-- cheap insurance and matches the re-grant done alongside every prior rebuild.
GRANT SELECT, INSERT, UPDATE, DELETE ON site.public_site_payload TO app_backend;

-- Drop the single-category column (its composite FK goes with it). The legacy
-- dead `category` TEXT column (pre-relational label, never read) goes too.
ALTER TABLE site.services DROP COLUMN IF EXISTS category_id;
ALTER TABLE site.services DROP COLUMN IF EXISTS category;

COMMIT;

-- ROLLBACK (structural):
-- ALTER TABLE site.services ADD COLUMN IF NOT EXISTS category_id uuid;
-- UPDATE site.services s SET category_id = sub.service_category_id
--   FROM (
--     SELECT DISTINCT ON (service_id) service_id, service_category_id
--     FROM site.service_category_assignments ORDER BY service_id, created_at
--   ) sub WHERE sub.service_id = s.id;
-- DROP TABLE IF EXISTS site.service_category_assignments;
