-- =====================================================================
-- Slice 7 Task 26a — repoint site.public_site_payload's `services` key at content.*
-- =====================================================================
-- This view is the LIVE RENDER PAYLOAD, not an API response: it backs
-- App\Models\Views\PublicSitePayload, which SyncSubdomainToKvJob (the ONLY
-- writer of SUBDOMAIN_KV) reads to build what the Cloudflare Worker serves for
-- every <handle>.partna.au sitepage. Its `services` key still selects from
-- site.services / site.service_category_assignments / site.service_categories,
-- all three on slice 7's drop list — so a bare DROP TABLE fails on the
-- dependency and a DROP ... CASCADE silently takes the view and every KV write
-- with it. This file MUST land before any of those drops.
--
-- The owner-authored services already live authoritatively in content.* (slice
-- 3a): content.items kind='service' reached through content.source_items on the
-- user's single kind='manual' content.sources row. The mapping below mirrors
-- App\Services\Content\ManualServiceItems (rows()/publicList()/facets()/
-- collectionsFor()) statement for statement rather than deriving a second
-- interpretation of it — two derivations of one mapping is the class of bug
-- this programme exists to remove.
--
-- Field-by-field, against the definition being replaced:
--   title            i.headline_cache, '' when null (publicList()'s (string) cast)
--   description      content.f_text.body on the manual source
--   price_cents      content.offers.amount_minor; 0 when there is no offer or
--   currency_code    qualifier='free', in which case currency is 'AUD' — the
--                    legacy column default (priceOf(): a hand-entered zero is
--                    stored as qualifier='free' with a NULL amount)
--   duration_minutes content.f_duration.seconds / 60, integer-truncated
--   is_active        constant true: the pre-image filtered on is_active=true, so
--                    the key was always true; the content.* equivalent of
--                    is_active=false is a section_items EXCLUDE, filtered below
--   sort_order       site.section_items.sort_key, a double, rounded through
--                    numeric so it rounds half-away-from-zero like PHP's round()
--                    rather than half-to-even like round(double precision)
--   category         the first owner-authored content.collections
--                    kind='service_category' membership, 'Services' when none
--
-- The one value that does NOT survive byte-identical is `id`: it becomes the
-- content.items id, where the pre-image emitted the site.services id. That is
-- deliberate and unavoidable — site.services is being dropped, so its ids cease
-- to exist, and the public API (SitepageDataResolverService::buildServicesData()
-- -> ManualServiceItems::publicList()) has emitted content item ids since slice
-- 3a. This makes the KV render payload agree with the API instead of quietly
-- disagreeing with it. Verified on dev: across all 22 published sites the two
-- `services` arrays are equal element-for-element and in the same order once
-- each legacy id is mapped through its `manual:<site.services.id>` coord.
--
-- Curation scope: section_items is joined only through THIS site's own
-- 'pool:services' section (site.sections is unique on (site_id, key)), matching
-- ManualServiceItems' section-scoped join — an item pinned into a second
-- section must not fan out. An item with no section_items row at all is SHOWN,
-- matching publicList()'s "absent state = shown".
--
-- Everything outside the `services` key is reproduced verbatim from the
-- deployed definition.
--
-- ROLLBACK: re-run this file's CREATE OR REPLACE VIEW with the `services` key
--           restored to its pre-image (transcribed in full at the foot of this
--           file). Column list and types are unchanged either way, so the
--           replace works in both directions with no DROP.
-- =====================================================================
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

CREATE OR REPLACE VIEW site.public_site_payload AS
SELECT
    s.id AS site_id,
    s.user_id,
    s.subdomain,
    jsonb_build_object(
        'site', jsonb_build_object(
            'id', s.id,
            'subdomain', s.subdomain,
            'settings', s.settings || jsonb_strip_nulls(jsonb_build_object(
                'show_branding', s.show_branding,
                'charlie_enabled', s.charlie_enabled,
                'services_auto_sync_enabled', s.services_auto_sync_enabled,
                'booking_mode', s.booking_mode,
                'manual_booking_url', s.manual_booking_url
            )),
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
        -- The repointed key. cs drives the FROM because idx_content_sources_manual
        -- is UNIQUE on (user_id) WHERE kind='manual' — one manual source per user,
        -- which is the same assumption ManualServiceItems::publicList() makes when
        -- it reads the facets off any row's source_id. Membership of that source is
        -- an EXISTS, not a join, because content.source_items is unique on
        -- (source_id, coord) and NOT on item_id: joining it would fan a
        -- twice-coorded item into two payload entries, which is what publicList()'s
        -- ->distinct() suppresses on the PHP side.
        'services', COALESCE((
            SELECT jsonb_agg(
                jsonb_build_object(
                    'id', i.id,
                    'title', COALESCE(i.headline_cache, ''::text),
                    'description', ft.body,
                    'price_cents', CASE WHEN o.qualifier IS NULL OR o.qualifier = 'free'::text
                        THEN 0 ELSE o.amount_minor END,
                    'currency_code', CASE WHEN o.qualifier IS NULL OR o.qualifier = 'free'::text
                        THEN 'AUD'::text ELSE o.currency END,
                    'duration_minutes', fd.seconds / 60,
                    'is_active', true,
                    'sort_order', round(sec.sort_key::numeric)::integer,
                    'category', COALESCE(cat.label, 'Services'::text)
                )
                ORDER BY (COALESCE(cat.position, 2147483647)),
                         (lower(COALESCE(cat.label, 'Services'::text))),
                         sec.sort_key,
                         i.created_at
            )
            FROM content.sources cs
                JOIN content.items i
                    ON i.user_id = cs.user_id
                    AND i.kind = 'service'::text
                    AND i.removed_at IS NULL
                LEFT JOIN site.section_items sec
                    ON sec.item_id = i.id
                    AND sec.section_id = (
                        SELECT sx.id FROM site.sections sx
                        WHERE sx.site_id = s.id AND sx.key = 'pool:services'::text
                    )
                LEFT JOIN content.f_text ft ON ft.item_id = i.id AND ft.source_id = cs.id
                LEFT JOIN content.f_duration fd ON fd.item_id = i.id AND fd.source_id = cs.id
                -- content.offers is keyed on id alone, so a second offer row is
                -- structurally possible; ordering by id makes the pick
                -- deterministic where the PHP keyBy('item_id') is merely
                -- last-row-wins. Every live owner service carries exactly one.
                LEFT JOIN LATERAL (
                    SELECT o2.qualifier, o2.amount_minor, o2.currency
                    FROM content.offers o2
                    WHERE o2.item_id = i.id AND o2.source_id = cs.id
                    ORDER BY o2.id
                    LIMIT 1
                ) o ON true
                -- collectionsFor(): owner-authored memberships only
                -- (source_id IS NULL), live categories only, first by membership
                -- position. ServiceCollections::list()'s "empty machine-derived
                -- collection" filter is not reproduced: a collection reached from
                -- a live item necessarily has a live member, so it can never be
                -- the empty case that filter drops.
                LEFT JOIN LATERAL (
                    SELECT c.label, c.position
                    FROM content.collection_items ci
                        JOIN content.collections c ON c.id = ci.collection_id
                    WHERE ci.item_id = i.id
                        AND ci.source_id IS NULL
                        AND c.user_id = cs.user_id
                        AND c.kind = 'service_category'::text
                        AND c.removed_at IS NULL
                    ORDER BY ci.position
                    LIMIT 1
                ) cat ON true
            WHERE cs.user_id = p.id
                AND cs.kind = 'manual'::text
                AND EXISTS (
                    SELECT 1 FROM content.source_items si
                    WHERE si.item_id = i.id
                        AND si.source_id = cs.id
                        AND si.removed_at IS NULL
                )
                AND (sec.state IS NULL OR sec.state <> 'excluded'::text)
        ), '[]'::jsonb)
    ) AS payload
FROM site.sites s
    JOIN core.users p ON p.id = s.user_id
WHERE s.is_published = true
    AND (p.status = ANY (ARRAY['active'::text, 'unclaimed'::text]))
    AND p.deleted_at IS NULL;

COMMIT;

-- Pre-image of the `services` key, for the ROLLBACK above. Only restorable
-- while site.services, site.service_category_assignments and
-- site.service_categories still exist; once slice 7's drops land there is no
-- reverse for this file at all.
--
--         'services', COALESCE((
--             SELECT jsonb_agg(
--                 jsonb_build_object(
--                     'id', sv.id,
--                     'title', sv.title,
--                     'description', sv.description,
--                     'price_cents', sv.price_cents,
--                     'currency_code', sv.currency_code,
--                     'duration_minutes', sv.duration_minutes,
--                     'is_active', sv.is_active,
--                     'sort_order', sv.sort_order,
--                     'category', COALESCE(sc.title, 'Services'::text)
--                 )
--                 ORDER BY (COALESCE(sc.sort_order, 2147483647)),
--                          (lower(COALESCE(sc.title, 'Services'::text))),
--                          sv.sort_order,
--                          sv.created_at
--             )
--             FROM site.services sv
--                 LEFT JOIN LATERAL (
--                     SELECT c.title, c.sort_order
--                     FROM site.service_category_assignments a
--                         JOIN site.service_categories c
--                             ON c.id = a.service_category_id AND c.deleted_at IS NULL
--                     WHERE a.service_id = sv.id
--                     ORDER BY c.sort_order, (lower(c.title))
--                     LIMIT 1
--                 ) sc ON true
--             WHERE sv.user_id = p.id
--                 AND sv.source IS NULL
--                 AND sv.is_active = true
--                 AND sv.deleted_at IS NULL
--         ), '[]'::jsonb)
