-- Remove professional legal contents — Shopify handles privacy policy and T&C.

-- 1. Drop RLS policies
DROP POLICY IF EXISTS legal_contents_pro_all ON core.professional_legal_contents;
DROP POLICY IF EXISTS legal_contents_staff_all ON core.professional_legal_contents;

-- 2. Drop trigger
DROP TRIGGER IF EXISTS set_timestamp_professional_legal_contents ON core.professional_legal_contents;

-- 3. Recreate the public_site_payload view without the legal content JOIN/key
CREATE OR REPLACE VIEW site.public_site_payload
WITH (security_invoker='on') AS
SELECT
  s.id as site_id,
  s.professional_id,
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
            'sort_order', sm.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'webp'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'gallery'
          AND sm.media_type = 'image'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'content_images', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'webp'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'content'
          AND sm.media_type = 'image'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'gallery_videos', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'media_type', sm.media_type,
            'processing_state', sm.processing_state,
            'duration_ms', sm.duration_ms,
            'poster', sm.poster_path,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'mp4'
            ), '{}'::jsonb),
            'streams', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'hls_playlist'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'gallery'
          AND sm.media_type = 'video'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'content_videos', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'sort_order', sm.sort_order,
            'media_type', sm.media_type,
            'processing_state', sm.processing_state,
            'duration_ms', sm.duration_ms,
            'poster', sm.poster_path,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'mp4'
            ), '{}'::jsonb),
            'streams', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'hls_playlist'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'content'
          AND sm.media_type = 'video'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb)
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
    'theme', CASE WHEN t.id IS NULL THEN NULL::jsonb ELSE jsonb_build_object('id', t.id, 'key', t.key, 'name', t.name, 'config', t.config) END,
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
      WHERE b.site_id = s.id AND b.block_group = 'links' AND b.is_active = true AND b.deleted_at IS NULL
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
      WHERE b.site_id = s.id AND b.block_group = 'sections' AND b.is_enabled = true AND b.is_active = true AND b.deleted_at IS NULL
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
          'category', COALESCE(sc.title, 'Services')
        )
        ORDER BY COALESCE(sc.sort_order, 2147483647), LOWER(COALESCE(sc.title, 'Services')), sv.sort_order, sv.created_at
      )
      FROM site.services sv
      LEFT JOIN site.service_categories sc
        ON sc.id = sv.category_id
        AND sc.deleted_at IS NULL
      WHERE sv.professional_id = p.id AND sv.is_active = true AND sv.deleted_at IS NULL
    ), '[]'::jsonb)
  ) as payload
FROM site.sites s
JOIN core.professionals p ON p.id = s.professional_id
LEFT JOIN site.themes t ON t.id = s.theme_id
WHERE
  s.is_published = true
  AND p.status = 'active'
  AND p.deleted_at IS NULL;

-- 4. Drop the table (cascades indexes and FK)
DROP TABLE IF EXISTS core.professional_legal_contents;
