-- SCHEMA-1 — analytics.item_views.item_type CHECK constraint.
--
-- CORRECTED vocabulary (10 values), NOT the 7-value list in the audit finding
-- (audits/archive/sweeps/2026-07-11-full-work-sweep/TRIAGE-2-P2.md SCHEMA-1), which
-- was copied from the stale DDL comment at
-- 20260709042911_create_item_views.sql:13. Source of truth is
-- App\Http\Requests\Api\PublicSite\Analytics\ItemSeenRequest::ITEM_TYPES,
-- confirmed by direct read of that file — the list matches exactly:
-- shop_product, menu_item, menu_category, service, block, gallery_item,
-- engine_item, listen_item, watch_item, link_item. The request class's own
-- docblock explains listen_item/watch_item/link_item were added 2026-07-10
-- alongside the ONE theme's tracks/videos/custom-links items, after the
-- item_views table's DDL comment was written.
--
-- Dev census (glncumufgaqcmqhzwrxm, 2026-07-20): 182 rows — link_item 18,
-- listen_item 16, service 83, shop_product 62, watch_item 3. VALIDATE is a
-- clean pass against the current data.
--
-- analytics.item_views is not a HOT_TABLE, but the NOT VALID -> VALIDATE
-- split (CONVENTIONS.md §2) still applies.

-- Window 1: add the CHECK in NOT VALID form.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.item_views
    ADD CONSTRAINT item_views_item_type_check
    CHECK (item_type IN (
        'shop_product', 'menu_item', 'menu_category', 'service', 'block',
        'gallery_item', 'engine_item', 'listen_item', 'watch_item', 'link_item'
    )) NOT VALID;

COMMIT;

-- Window 2: validate in a second transaction.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.item_views VALIDATE CONSTRAINT item_views_item_type_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE analytics.item_views DROP CONSTRAINT IF EXISTS item_views_item_type_check;
-- COMMIT;
