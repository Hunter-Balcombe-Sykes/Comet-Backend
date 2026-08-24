-- Unified actions + content ordering (spec: docs/superpowers/specs/
-- 2026-08-23-unified-actions-and-ordering-design.md). Owner decision: every
-- existing account is a test account, so legacy preferences are DROPPED, not
-- migrated.
--
--  1. content_popularity_scores.content_type loses 'page': pages are actions
--     now (page:<id> rows in the 'action' family). The CHECK is lockstep-tested
--     against this file by tests/Feature/Database/ConstraintVocabularyLockstepTest.php.
--  2. Stored 'page' and 'action' rows are deleted — the action keys change
--     grammar (instagram → platform:instagram) and the job recomputes within
--     15 minutes.
--  3. action_events rows keyed by the retired vocabulary are deleted so the
--     table only ever holds <kind>:<ref> ids.
--  4. site.sites.settings loses smart_actions / manual_actions /
--     manual_order_pools (replaced by settings.actions + settings.pool_order).
--
-- VALIDATE CONSTRAINT runs in 20260823100001_unified_actions_validate.sql.
--
-- ROLLBACK:
--   ALTER TABLE analytics.content_popularity_scores DROP CONSTRAINT IF EXISTS content_popularity_scores_content_type_check;
--   ALTER TABLE analytics.content_popularity_scores ADD CONSTRAINT content_popularity_scores_content_type_check
--     CHECK (content_type = ANY (ARRAY['page','action','shop_product','menu_item','menu_category','service','block','gallery_item','engine_item','listen_item','watch_item','link_item']));
--   (deleted rows and settings keys are not recoverable — test data only)
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.content_popularity_scores
    DROP CONSTRAINT IF EXISTS content_popularity_scores_content_type_check;

DELETE FROM analytics.content_popularity_scores
    WHERE content_type = 'page' OR content_type = 'action';

ALTER TABLE analytics.content_popularity_scores
    ADD CONSTRAINT content_popularity_scores_content_type_check
    CHECK (content_type IN (
        'action', 'shop_product', 'menu_item', 'menu_category',
        'service', 'block', 'gallery_item', 'engine_item',
        'listen_item', 'watch_item', 'link_item'
    )) NOT VALID;

DELETE FROM analytics.action_events
    WHERE action_id !~ '^(page|platform|item|category):';

UPDATE site.sites
    SET settings = settings - 'smart_actions' - 'manual_actions' - 'manual_order_pools'
    WHERE settings ?| ARRAY['smart_actions', 'manual_actions', 'manual_order_pools'];

COMMIT;
