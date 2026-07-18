-- Pre-Account Sites (spec 2026-07-18): provisional account-less users + build audit table.
-- Owners are account-less until claim: auth_user_id/primary_email become nullable,
-- status gains 'unclaimed', and the public payload view treats unclaimed owners as
-- renderable (publish state is a separate knob).
--
-- guard:no-unsafe-migrations:disable-file
-- core.users is pre-beta / no live customers (CLAUDE.md "Current Project Status") —
-- the two unique-index rebuilds and the status CHECK swap below take a momentary
-- ACCESS EXCLUSIVE lock on a near-empty table, same justification class as
-- 20260714200000_architecture_one_to_staple.sql's exemption. The brief's plan was
-- reviewed/approved with this exact DDL shape (not the 4-step NOT VALID pattern);
-- this opt-out only silences the lint, it changes no statement below.

-- 1. Nullability — provisional users have no auth user and no email yet.
ALTER TABLE core.users ALTER COLUMN auth_user_id DROP NOT NULL;
ALTER TABLE core.users ALTER COLUMN primary_email DROP NOT NULL;

-- 2. Rebuild the partial unique indexes: combine the NEW null-exclusion with the
--    EXISTING soft-delete predicate (verified live: both currently WHERE deleted_at IS NULL).
DROP INDEX IF EXISTS core.users_auth_user_id_unique;
CREATE UNIQUE INDEX users_auth_user_id_unique ON core.users (auth_user_id)
  WHERE auth_user_id IS NOT NULL AND deleted_at IS NULL;

DROP INDEX IF EXISTS core.users_email_unique;
CREATE UNIQUE INDEX users_email_unique ON core.users (lower(primary_email))
  WHERE primary_email IS NOT NULL AND deleted_at IS NULL;

-- 3. Widen the status vocabulary (verified live: CHECK exists in the baseline).
ALTER TABLE core.users DROP CONSTRAINT users_status_check;
ALTER TABLE core.users ADD CONSTRAINT users_status_check
  CHECK (status IN ('active', 'suspended', 'disabled', 'pending_deletion', 'unclaimed'));

-- 4. Build audit table — 1:1 with the provisional user, survives claim (permanent
--    origin record, NOT a ledger of ongoing source interactions).
CREATE TABLE core.pre_account_builds (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id           uuid NOT NULL UNIQUE REFERENCES core.users(id) ON DELETE CASCADE,
  source_type       text NOT NULL CHECK (source_type IN ('instagram', 'google_business')),
  source_ref        text NOT NULL,
  source_ref_lc     text NOT NULL,
  built_via         text NOT NULL CHECK (built_via IN ('signup', 'staff')),
  built_by_staff_id uuid NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
  build_state       text NOT NULL DEFAULT 'pending'
                    CHECK (build_state IN ('pending', 'building', 'ready', 'failed')),
  failure_code      text NULL,
  created_ip_hash   text NULL,          -- sha256(CF-Connecting-IP); NULL for staff builds (F2)
  expires_at        timestamptz NOT NULL,
  claimed_at        timestamptz NULL,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now()
);

-- One LIVE unclaimed build per source: retyping the same handle re-serves the
-- existing build instead of stacking squatters / re-scraping.
CREATE UNIQUE INDEX pre_account_builds_live_source_unique
  ON core.pre_account_builds (source_type, source_ref_lc)
  WHERE claimed_at IS NULL;

CREATE INDEX pre_account_builds_expiry_idx
  ON core.pre_account_builds (expires_at) WHERE claimed_at IS NULL;

-- Outstanding-per-IP abuse cap lookup (F2).
CREATE INDEX pre_account_builds_ip_idx
  ON core.pre_account_builds (created_ip_hash) WHERE claimed_at IS NULL AND created_ip_hash IS NOT NULL;

-- Same app_backend grant + RLS-on/staff+service-policy shape as the two most
-- recent core-schema table creations (20260711000300_early_access_signups.sql,
-- 20260711000100_user_segments.sql) — the live-safe posture established by
-- 20260710140000_rls_policies_new_tables.sql. No owner-select policy: a
-- provisional user has no auth_user_id, so there is no authenticated principal
-- to match rows against until claim.
ALTER TABLE core.pre_account_builds ENABLE ROW LEVEL SECURITY;

GRANT SELECT, INSERT, UPDATE, DELETE ON core.pre_account_builds TO app_backend;

DROP POLICY IF EXISTS pre_account_builds_staff_select ON core.pre_account_builds;
CREATE POLICY pre_account_builds_staff_select ON core.pre_account_builds
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

DROP POLICY IF EXISTS pre_account_builds_service_role_all ON core.pre_account_builds;
CREATE POLICY pre_account_builds_service_role_all ON core.pre_account_builds
    FOR ALL TO service_role
    USING (true) WITH CHECK (true);

-- 5. Public payload view: unclaimed owners render (publish state is the visibility knob).
-- Body copied from the latest full definition (20260705120000_drop_dead_profile_features.sql,
-- the DROP VIEW + CREATE VIEW that superseded 20260701200000's CREATE OR REPLACE — nothing
-- later fully redefines the view). Two corrections beyond the brief's WHERE-clause change:
-- `s.skeleton_id` -> `s.architecture_id` in both value positions, because
-- 20260710230000_rename_skeleton_id_to_architecture_id.sql renamed the underlying
-- site.sites column after 20260705120000 was written; the JSONB output KEY stays the
-- literal string 'skeleton_id' on purpose (documented wire-compat contract in that same
-- migration and in 20260714200000_architecture_one_to_staple.sql — the public payload
-- keeps emitting `skeleton_id` until consumers migrate to `architectureId`).
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
                LEFT JOIN site.service_categories sc ON sc.id = sv.category_id AND sc.deleted_at IS NULL
            WHERE sv.user_id = p.id
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
