-- Teardown — removes the load-test handle and ALL its rows (seed + write-scenario
-- traffic). Scoped to the fixed test IDs. RUN AGAINST DEV ONLY.
DO $$
DECLARE
  v_user uuid := '00000000-0000-4000-a000-000000000001';
  v_site uuid := '00000000-0000-4000-a000-000000000002';
BEGIN
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
END $$;
