-- service_category joins the item taxonomy (smart ordering v2 — D2:
-- category score = SUM of member item scores, keyed by collection id, one
-- family per category pool: menu_category already existed, service_category
-- is new). Both CHECKs that carry the taxonomy widen together, because
-- ItemSeenRequest::ITEM_TYPES is the single app-side vocabulary behind
-- item_views.item_type AND (with 'action') content_popularity_scores.
-- content_type — tests/Feature/Database/ConstraintVocabularyLockstepTest.php
-- reads BOTH IN (...) lists out of this file.
--
-- NOT VALID here; VALIDATE CONSTRAINT runs in
-- 20260823130001_service_category_family_validate.sql (CONVENTIONS §2).
--
-- ROLLBACK:
--   ALTER TABLE analytics.content_popularity_scores DROP CONSTRAINT IF EXISTS content_popularity_scores_content_type_check;
--   ALTER TABLE analytics.content_popularity_scores ADD CONSTRAINT content_popularity_scores_content_type_check
--     CHECK (content_type = ANY (ARRAY['action','shop_product','menu_item','menu_category','service','block','gallery_item','engine_item','listen_item','watch_item','link_item']));
--   ALTER TABLE analytics.item_views DROP CONSTRAINT IF EXISTS item_views_item_type_check;
--   ALTER TABLE analytics.item_views ADD CONSTRAINT item_views_item_type_check
--     CHECK (item_type = ANY (ARRAY['shop_product','menu_item','menu_category','service','block','gallery_item','engine_item','listen_item','watch_item','link_item']));
--   (delete any service_category rows first)
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.content_popularity_scores
    DROP CONSTRAINT IF EXISTS content_popularity_scores_content_type_check;

ALTER TABLE analytics.content_popularity_scores
    ADD CONSTRAINT content_popularity_scores_content_type_check
    CHECK (content_type IN (
        'action', 'shop_product', 'menu_item', 'menu_category',
        'service', 'service_category', 'block', 'gallery_item', 'engine_item',
        'listen_item', 'watch_item', 'link_item'
    )) NOT VALID;

ALTER TABLE analytics.item_views
    DROP CONSTRAINT IF EXISTS item_views_item_type_check;

ALTER TABLE analytics.item_views
    ADD CONSTRAINT item_views_item_type_check
    CHECK (item_type IN (
        'shop_product', 'menu_item', 'menu_category',
        'service', 'service_category', 'block', 'gallery_item', 'engine_item',
        'listen_item', 'watch_item', 'link_item'
    )) NOT VALID;

COMMIT;
