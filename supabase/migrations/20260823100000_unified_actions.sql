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
--  3. action_events rows keyed by the retired vocabulary, and site.sites.settings
--     keys smart_actions/manual_actions/manual_order_pools, are legacy DATA
--     cleanup, not schema — moved to `php artisan partna:scrub-unified-actions-legacy`
--     (App\Console\Commands\ScrubUnifiedActionsLegacyCommand) so they aren't
--     re-run (as a no-op) on every from-zero apply. Wired into
--     docs/deploy/routine-deploy.md as a post-deploy step.
--
-- WHY THE content_type = 'page' DELETE STAYS INLINE, UNLIKE THE OTHER TWO:
-- the ADD CONSTRAINT ... NOT VALID below still enforces on every NEW write
-- from the moment this transaction commits, and analytics:compute-popularity
-- (ComputeContentPopularityScores) re-upserts every stored content_type it
-- finds on its next run — a surviving 'page' row makes that job throw a CHECK
-- violation, and would also abort 20260823100001's VALIDATE CONSTRAINT scan.
-- It cannot be split into its own file either: the Supabase CLI keys the
-- migration ledger on the leading 14-digit timestamp, so no filename can sort
-- between this file (...100000) and its VALIDATE half (...100001) without a
-- version collision.
--
-- VALIDATE CONSTRAINT runs in 20260823100001_unified_actions_validate.sql.
--
-- ROLLBACK:
--   ALTER TABLE analytics.content_popularity_scores DROP CONSTRAINT IF EXISTS content_popularity_scores_content_type_check;
--   ALTER TABLE analytics.content_popularity_scores ADD CONSTRAINT content_popularity_scores_content_type_check
--     CHECK (content_type = ANY (ARRAY['page','action','shop_product','menu_item','menu_category','service','block','gallery_item','engine_item','listen_item','watch_item','link_item']));
--   (deleted 'page'/'action' rows are not recoverable — test data only. The
--   action_events/settings cleanup moved to the command above — see its own
--   file for reverting those, though the deleted rows there are equally
--   unrecoverable.)
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

COMMIT;
