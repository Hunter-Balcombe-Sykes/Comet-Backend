-- event_item joins the item taxonomy (smart-scoring plan, 2026-08-27 —
-- events score now: ticket link-outs + views aggregate per event, the
-- additive term is EventTimeRelevance peaking at the event date). Both
-- CHECKs that carry the taxonomy widen together, because
-- ItemSeenRequest::ITEM_TYPES is the single app-side vocabulary behind
-- item_views.item_type AND (with 'action') content_popularity_scores.
-- content_type — tests/Feature/Database/ConstraintVocabularyLockstepTest.php
-- reads BOTH IN (...) lists out of this file.
--
-- NOT VALID first, VALIDATE in its OWN transaction (CONVENTIONS §2): the
-- validation scan then runs under SHARE UPDATE EXCLUSIVE instead of the
-- ADD's heavier lock. Cheap here (small tables), but the pattern is the
-- pattern — the safety lint holds every migration to it.
--
-- ROLLBACK:
--   (delete any event_item rows first, then recreate both CHECKs from
--    20260823130000_service_category_family.sql's lists)

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
        'listen_item', 'watch_item', 'link_item', 'event_item'
    )) NOT VALID;

ALTER TABLE analytics.item_views
    DROP CONSTRAINT IF EXISTS item_views_item_type_check;

ALTER TABLE analytics.item_views
    ADD CONSTRAINT item_views_item_type_check
    CHECK (item_type IN (
        'shop_product', 'menu_item', 'menu_category',
        'service', 'service_category', 'block', 'gallery_item', 'engine_item',
        'listen_item', 'watch_item', 'link_item', 'event_item'
    )) NOT VALID;

COMMIT;

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.content_popularity_scores
    VALIDATE CONSTRAINT content_popularity_scores_content_type_check;

ALTER TABLE analytics.item_views
    VALIDATE CONSTRAINT item_views_item_type_check;

COMMIT;
