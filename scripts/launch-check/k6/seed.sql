-- Phase 0 seed — representative volume for the load-test handle.
-- Scoped ENTIRELY to the fixed test IDs below. Idempotent (deletes first).
-- RUN AGAINST DEV ONLY (Supabase ref glncumufgaqcmqhzwrxm).
DO $$
DECLARE
  v_user uuid := '00000000-0000-4000-a000-000000000001';
  v_site uuid := '00000000-0000-4000-a000-000000000002';
BEGIN
  -- ---- teardown-then-seed (idempotent re-run) ----
  DELETE FROM analytics.link_clicks   WHERE site_id = v_site;
  DELETE FROM analytics.section_views WHERE site_id = v_site;
  DELETE FROM analytics.site_visits   WHERE site_id = v_site;
  DELETE FROM analytics.content_popularity_scores WHERE site_id = v_site;
  DELETE FROM site.services   WHERE user_id = v_user;
  DELETE FROM site.site_media WHERE site_id = v_site;
  DELETE FROM site.blocks     WHERE site_id = v_site;
  DELETE FROM site.design_kits WHERE site_id = v_site;
  DELETE FROM site.sites      WHERE id = v_site;
  DELETE FROM core.users      WHERE id = v_user;

  -- ---- user + published site ----
  INSERT INTO core.users (id, handle, handle_lc, display_name, first_name, status, account_type)
  VALUES (v_user, 'loadtest', 'loadtest', 'Load Test', 'Load', 'active', 'partna');

  INSERT INTO site.sites (id, user_id, subdomain, is_published, architecture_id)
  VALUES (v_site, v_user, 'loadtest', true, 'staple');
  -- design_kits row auto-inserted by the on-create trigger.

  -- ---- link blocks (links engine) — 10 ----
  INSERT INTO site.blocks
    (user_id, site_id, block_type, block_group, title, url, sort_order, is_active, is_enabled, category, platform)
  SELECT v_user, v_site, 'link', 'links',
         'Link ' || g, 'https://example.com/' || g, g, true, true, 'social', 'instagram'
  FROM generate_series(1, 10) g;

  -- ---- section blocks (must be is_enabled=true to render) ----
  INSERT INTO site.blocks
    (user_id, site_id, block_type, block_group, title, sort_order, is_active, is_enabled, settings)
  VALUES
    (v_user, v_site, 'gallery',    'sections', 'Gallery',    1, true, true, '{}'::jsonb),
    (v_user, v_site, 'services',   'sections', 'Services',   2, true, true, '{}'::jsonb),
    (v_user, v_site, 'contact',    'sections', 'Contact',    3, true, true, '{"subject_options":["General"]}'::jsonb),
    (v_user, v_site, 'newsletter', 'sections', 'Newsletter', 4, true, true, '{"input_placeholder":"Your email"}'::jsonb),
    (v_user, v_site, 'workplace',  'sections', 'Workplace',  5, true, true, '{"name":"Test Studio","city":"Melbourne"}'::jsonb);

  -- ---- gallery media — 6 ready images (site.site_media 'gallery' pool is hard-capped
  -- at 6 per site by the core.enforce_site_gallery_max6 trigger, baseline_pilot.sql:191 —
  -- this is the real-world ceiling for any production site, so 6 IS representative) ----
  INSERT INTO site.site_media
    (site_id, bucket, path, alt_text, sort_order, is_active, pool, media_type, processing_state)
  SELECT v_site, 'public-assets', 'loadtest/img-' || g || '.webp',
         'Gallery image ' || g, g, true, 'gallery', 'image', 'ready'
  FROM generate_series(1, 6) g;

  -- ---- gallery media variants — required for a non-empty gallery URL. Raw SQL
  -- bypasses SiteMediaObserver / ProcessImageVariantsJob (the real upload
  -- pipeline that normally writes these), so getGallery() filters every item
  -- out on url==='' without this: SiteMedia::variantUrls() only reads
  -- artifact_type='webp' rows, keyed by variant_key ('optimized' preferred).
  INSERT INTO site.media_variants (media_id, variant_key, artifact_type, disk, path)
  SELECT id, 'optimized', 'webp', 'media', 'loadtest/img-' || sort_order || '-optimized.webp'
  FROM site.site_media WHERE site_id = v_site AND pool = 'gallery';

  -- ---- services — 15 owner-authored (source NULL => public-visible) ----
  INSERT INTO site.services
    (user_id, title, description, price_cents, currency_code, duration_minutes, is_active, sort_order)
  SELECT v_user, 'Service ' || g, 'Description for service ' || g,
         5000 + g * 500, 'AUD', 30 + (g % 4) * 15, true, g
  FROM generate_series(1, 15) g;

  -- ---- popularity scores — read on the hot path (services + gallery + link blocks) ----
  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'service', s.id::text, random() * 100,
         row_number() OVER (ORDER BY random())
  FROM site.services s WHERE s.user_id = v_user;

  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'gallery_item', m.id::text, random() * 100,
         row_number() OVER (ORDER BY random())
  FROM site.site_media m WHERE m.site_id = v_site;

  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'block', b.id::text, random() * 100,
         row_number() OVER (ORDER BY random())
  FROM site.blocks b WHERE b.site_id = v_site AND b.block_group = 'links';

  -- ---- raw analytics events — realistic table volume (minimal confirmed columns) ----
  INSERT INTO analytics.site_visits (user_id, site_id)
  SELECT v_user, v_site FROM generate_series(1, 3000);

  INSERT INTO analytics.link_clicks (user_id, site_id, url)
  SELECT v_user, v_site, 'https://example.com/' || (g % 10)
  FROM generate_series(1, 1500) g;

  INSERT INTO analytics.section_views (user_id, site_id, section_key)
  SELECT v_user, v_site, (ARRAY['gallery','services','contact'])[1 + (g % 3)]
  FROM generate_series(1, 1500) g;
END $$;
