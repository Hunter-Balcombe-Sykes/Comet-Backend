-- Phase 0 seed — representative volume for the load-test handle.
-- Scoped ENTIRELY to the fixed test IDs below. Idempotent (deletes first).
-- RUN AGAINST DEV ONLY (Supabase ref glncumufgaqcmqhzwrxm).
--
-- REBUILT 2026-09-02. This file seeded site.services and a site_media 'gallery'
-- pool, both of which no longer exist:
--   * site.services / site.service_categories were DROPPED 2026-08-18 (services
--     cutover) — the seed raised 42P01 partway through and the harness could not
--     run at all.
--   * the site_media 'gallery' usage was retired 2026-09-01/02 (and the
--     column itself became site_media.usage on 2026-09-04).
-- Curated content now lives in content.*, so the seed has to mint the real
-- shapes. Row shapes below were read off live dev rows, not invented.
--
-- TWO LANES, and they publish by DIFFERENT mechanisms — this is the part that
-- makes a naive seed silently produce an empty page:
--   * SERVICES reach profile.services through ManualServiceItems::publicList,
--     which INNER JOINs content.source_items -> content.sources filtered
--     kind='manual'. A bare content.items row is invisible to it. No pin needed:
--     the services pool rule is kind_is, so every service item publishes.
--   * MEDIA is opt-in. Its rule is kind_is + latest_n_per_auto_source, and a
--     MANUAL source is not an auto source — so manual media items publish only
--     when PINNED. Hence the pool:media section and its six section_items rows.
--     Seeding the items without the pins yields pools.media.items == [].
DO $$
DECLARE
  v_user   uuid := '00000000-0000-4000-a000-000000000001';
  v_site   uuid := '00000000-0000-4000-a000-000000000002';
  v_source uuid := '00000000-0000-4000-a000-000000000003';
  v_page   uuid := '00000000-0000-4000-a000-000000000004';
  v_section uuid := '00000000-0000-4000-a000-000000000005';
BEGIN
  -- ---- teardown-then-seed (idempotent re-run). FK order: leaves first. ----
  DELETE FROM analytics.link_clicks   WHERE site_id = v_site;
  DELETE FROM analytics.section_views WHERE site_id = v_site;
  DELETE FROM analytics.site_visits   WHERE site_id = v_site;
  DELETE FROM analytics.content_popularity_scores WHERE site_id = v_site;

  DELETE FROM site.section_items WHERE section_id IN (SELECT id FROM site.sections WHERE site_id = v_site);
  DELETE FROM site.sections      WHERE site_id = v_site;
  DELETE FROM site.pages         WHERE site_id = v_site;

  DELETE FROM content.item_media   WHERE item_id IN (SELECT id FROM content.items WHERE user_id = v_user);
  DELETE FROM content.f_text       WHERE item_id IN (SELECT id FROM content.items WHERE user_id = v_user);
  DELETE FROM content.f_link       WHERE item_id IN (SELECT id FROM content.items WHERE user_id = v_user);
  DELETE FROM content.item_anchors WHERE user_id = v_user;
  DELETE FROM content.source_items WHERE source_id IN (SELECT id FROM content.sources WHERE user_id = v_user);
  DELETE FROM content.media_assets WHERE user_id = v_user;
  DELETE FROM content.items        WHERE user_id = v_user;
  DELETE FROM content.sources      WHERE user_id = v_user;

  DELETE FROM site.media_variants WHERE media_id IN (SELECT id FROM site.site_media WHERE site_id = v_site);
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

  -- ---- byte lane: 6 ready images in the CONTENT usage ('gallery' retired
  -- 2026-09-01; the content usage's cap is 20 via config partna.upload_limits).
  -- 6 is kept as the seeded count so earlier baseline results stay comparable.
  INSERT INTO site.site_media
    (site_id, bucket, path, alt_text, sort_order, is_active, "usage", media_type, processing_state)
  SELECT v_site, 'public-assets', 'loadtest/img-' || g || '.webp',
         'Gallery image ' || g, g, true, 'content', 'image', 'ready'
  FROM generate_series(1, 6) g;

  -- ---- media variants — required or every URL resolves empty. Raw SQL bypasses
  -- SiteMediaObserver / ProcessImageVariantsJob (the real pipeline that writes
  -- these): SiteMedia::variantUrls() only reads artifact_type='webp' rows.
  INSERT INTO site.media_variants (media_id, variant_key, artifact_type, disk, path)
  SELECT id, 'optimized', 'webp', 'media', 'loadtest/img-' || sort_order || '-optimized.webp'
  FROM site.site_media WHERE site_id = v_site AND "usage" = 'content';

  -- ---- the manual source. idx_content_sources_manual is UNIQUE on user_id
  -- WHERE kind='manual', so there is exactly ONE per user; both lanes share it.
  -- priority 200 = ProjectionWriter::MANUAL_SOURCE_PRIORITY.
  INSERT INTO content.sources (id, user_id, kind, connection_id, label, priority, created_at, updated_at)
  VALUES (v_source, v_user, 'manual', NULL, 'manual', 200, now(), now());

  -- ---- SERVICES — 15 owner-authored, on the manual lane ----
  INSERT INTO content.items (id, user_id, kind, headline_cache, facets_cache, first_seen_at, last_seen_at, created_at, updated_at)
  SELECT ('00000000-0000-4000-b000-' || lpad(g::text, 12, '0'))::uuid,
         v_user, 'service', 'Service ' || g, '["f_text"]'::jsonb, now(), now(), now(), now()
  FROM generate_series(1, 15) g;

  INSERT INTO content.source_items (id, source_id, coord, item_id, kind, projector_version, first_seen_at, last_seen_at)
  SELECT gen_random_uuid(), v_source,
         'manual:' || ('00000000-0000-4000-b000-' || lpad(g::text, 12, '0')),
         ('00000000-0000-4000-b000-' || lpad(g::text, 12, '0'))::uuid,
         'service', 0, now(), now()
  FROM generate_series(1, 15) g;

  INSERT INTO content.item_anchors (coord, user_id, item_id, bound_at)
  SELECT 'manual:' || ('00000000-0000-4000-b000-' || lpad(g::text, 12, '0')),
         v_user, ('00000000-0000-4000-b000-' || lpad(g::text, 12, '0'))::uuid, now()
  FROM generate_series(1, 15) g;

  INSERT INTO content.f_text (item_id, source_id, headline, body, updated_at)
  SELECT ('00000000-0000-4000-b000-' || lpad(g::text, 12, '0'))::uuid, v_source,
         'Service ' || g, 'Description for service ' || g, now()
  FROM generate_series(1, 15) g;

  -- ---- CUSTOM LINKS — 10 pool items. The site.blocks 'links' rows above are
  -- NOT the public surface any more: verified 2026-09-02 against dev-api, a
  -- profile with 10 link BLOCKS and no link ITEMS serves no links at all (and
  -- there is no `profile.links` key on the wire to carry them). The blocks stay
  -- because a real site has them and the analytics rows below key off them.
  -- custom_links takes the kind_is rule, so these publish without pins.
  INSERT INTO content.items (id, user_id, kind, headline_cache, facets_cache, first_seen_at, last_seen_at, created_at, updated_at)
  SELECT ('00000000-0000-4000-e000-' || lpad(g::text, 12, '0'))::uuid,
         v_user, 'link', 'Link ' || g, '["f_text","f_link"]'::jsonb, now(), now(), now(), now()
  FROM generate_series(1, 10) g;

  INSERT INTO content.source_items (id, source_id, coord, item_id, kind, projector_version, first_seen_at, last_seen_at)
  SELECT gen_random_uuid(), v_source,
         'manual:' || ('00000000-0000-4000-e000-' || lpad(g::text, 12, '0')),
         ('00000000-0000-4000-e000-' || lpad(g::text, 12, '0'))::uuid, 'link', 0, now(), now()
  FROM generate_series(1, 10) g;

  INSERT INTO content.item_anchors (coord, user_id, item_id, bound_at)
  SELECT 'manual:' || ('00000000-0000-4000-e000-' || lpad(g::text, 12, '0')),
         v_user, ('00000000-0000-4000-e000-' || lpad(g::text, 12, '0'))::uuid, now()
  FROM generate_series(1, 10) g;

  INSERT INTO content.f_text (item_id, source_id, headline, updated_at)
  SELECT ('00000000-0000-4000-e000-' || lpad(g::text, 12, '0'))::uuid, v_source, 'Link ' || g, now()
  FROM generate_series(1, 10) g;

  INSERT INTO content.f_link (item_id, source_id, url, updated_at)
  SELECT ('00000000-0000-4000-e000-' || lpad(g::text, 12, '0'))::uuid, v_source, 'https://example.com/' || g, now()
  FROM generate_series(1, 10) g;

  -- ---- MEDIA — 6 pool items bridged onto the six site_media rows.
  -- media_assets.site_media_id is the bridge the upload lane writes; with it,
  -- MediaUrlResolver reads the webp variant above, so source_url/storage_path
  -- stay NULL exactly as they are for a real upload.
  INSERT INTO content.items (id, user_id, kind, headline_cache, facets_cache, first_seen_at, last_seen_at, created_at, updated_at)
  SELECT ('00000000-0000-4000-c000-' || lpad(g::text, 12, '0'))::uuid,
         v_user, 'media', NULL, '["f_text","f_published","item_media"]'::jsonb, now(), now(), now(), now()
  FROM generate_series(1, 6) g;

  INSERT INTO content.source_items (id, source_id, coord, item_id, kind, projector_version, first_seen_at, last_seen_at)
  SELECT gen_random_uuid(), v_source,
         'upload:' || ('00000000-0000-4000-c000-' || lpad(g::text, 12, '0')),
         ('00000000-0000-4000-c000-' || lpad(g::text, 12, '0'))::uuid,
         'media', 0, now(), now()
  FROM generate_series(1, 6) g;

  INSERT INTO content.item_anchors (coord, user_id, item_id, bound_at)
  SELECT 'upload:' || ('00000000-0000-4000-c000-' || lpad(g::text, 12, '0')),
         v_user, ('00000000-0000-4000-c000-' || lpad(g::text, 12, '0'))::uuid, now()
  FROM generate_series(1, 6) g;

  INSERT INTO content.media_assets (id, user_id, fingerprint, mime_type, width, height, site_media_id, created_at)
  SELECT ('00000000-0000-4000-d000-' || lpad(g::text, 12, '0'))::uuid,
         v_user, 'loadtest-img-' || g, 'image/webp', 1200, 1200,
         (SELECT id FROM site.site_media WHERE site_id = v_site AND "usage" = 'content' AND sort_order = g),
         now()
  FROM generate_series(1, 6) g;

  INSERT INTO content.item_media (id, item_id, source_id, asset_id, role, position, created_at)
  SELECT gen_random_uuid(),
         ('00000000-0000-4000-c000-' || lpad(g::text, 12, '0'))::uuid, v_source,
         ('00000000-0000-4000-d000-' || lpad(g::text, 12, '0'))::uuid,
         'cover', 0, now()
  FROM generate_series(1, 6) g;

  -- ---- the media section + its pins. Without these the six items sit in the
  -- LIBRARY and pools.media publishes nothing (see the header note).
  INSERT INTO site.pages (id, site_id, key, label) VALUES (v_page, v_site, 'gallery', 'Gallery');

  INSERT INTO site.sections (id, page_id, site_id, key, label, slot, kind, sort_order, rule, mode, order_by, min_items, on_empty)
  VALUES (v_section, v_page, v_site, 'pool:media', 'Gallery', 'body', 'collection', 0,
          '{"all":[{"op":"kind_is","values":["media"]},{"op":"latest_n_per_auto_source","values":["media"]}]}'::jsonb,
          'mixed', 'recency', 1, 'hide');

  INSERT INTO site.section_items (id, section_id, item_id, state, sort_key, created_at)
  SELECT gen_random_uuid(), v_section,
         ('00000000-0000-4000-c000-' || lpad(g::text, 12, '0'))::uuid, 'pinned', g, now()
  FROM generate_series(1, 6) g;

  -- ---- popularity scores — read on the hot path (services + media + links) ----
  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'service', i.id::text, random() * 100, row_number() OVER (ORDER BY random())
  FROM content.items i WHERE i.user_id = v_user AND i.kind = 'service';

  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'gallery_item', i.id::text, random() * 100, row_number() OVER (ORDER BY random())
  FROM content.items i WHERE i.user_id = v_user AND i.kind = 'media';

  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'block', b.id::text, random() * 100, row_number() OVER (ORDER BY random())
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
