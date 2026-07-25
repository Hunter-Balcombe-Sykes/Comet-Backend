-- SCHEMA-2 — analytics.content_popularity_scores.content_type CHECK constraint.
--
-- CORRECTED vocabulary (12 values), NOT the 8-value list in the audit
-- finding (audits/sweeps/2026-07-11-full-work-sweep/TRIAGE-2-P2.md SCHEMA-2),
-- which was copied from the stale DDL comment at
-- 20260709042716_create_content_popularity_scores.sql:10. That comment
-- predates two later additions:
--   - listen_item / watch_item / link_item — added 2026-07-10 alongside the
--     ONE theme's tracks/videos/custom-links items (see the ITEM_TYPES
--     docblock in app/Http/Requests/Api/PublicSite/Analytics/ItemSeenRequest.php).
--   - action — owned by RankedActionsComputer::CONTENT_TYPE = 'action'
--     (app/Services/Analytics/RankedActionsComputer.php:42), written by the
--     nightly analytics:compute-popularity job (ComputeContentPopularityScores)
--     for the derived unified ranked-action layer. Verified both claims
--     directly against the current source.
--
-- The full set is: the 10-value item taxonomy (matching
-- analytics.item_views.item_type, see the sibling item_views_item_type_check
-- migration in this batch) + 'page' (section/page grain) + 'action' (derived
-- ranked-action layer). The constraint must accommodate what the app CAN
-- write, not only what it HAS written today — shipping the stale 8-value list
-- would fail VALIDATE the moment 'action'/listen_item/watch_item/link_item
-- rows exist, and would break the nightly analytics:compute-popularity job.
--
-- Dev census (glncumufgaqcmqhzwrxm, 2026-07-20): 72 rows — link_item 6,
-- listen_item 4, page 24, service 19, shop_product 18, watch_item 1. Zero
-- 'action' rows today (the derived layer hasn't run against this data yet)
-- but the constraint must allow it. VALIDATE is a clean pass against the
-- current data.
--
-- analytics.content_popularity_scores is not a HOT_TABLE, but the
-- NOT VALID -> VALIDATE split (CONVENTIONS.md §2) still applies.

-- Window 1: add the CHECK in NOT VALID form.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.content_popularity_scores
    ADD CONSTRAINT content_popularity_scores_content_type_check
    CHECK (content_type IN (
        'page', 'action', 'shop_product', 'menu_item', 'menu_category',
        'service', 'block', 'gallery_item', 'engine_item', 'listen_item',
        'watch_item', 'link_item'
    )) NOT VALID;

COMMIT;

-- Window 2: validate in a second transaction.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.content_popularity_scores VALIDATE CONSTRAINT content_popularity_scores_content_type_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE analytics.content_popularity_scores DROP CONSTRAINT IF EXISTS content_popularity_scores_content_type_check;
-- COMMIT;
