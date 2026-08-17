-- Slice 7 Phase 6 — legacy teardown, 2 of 4 menu tables.
-- ROLLBACK: NONE. A DROP TABLE has no reverse. The per-platform prices and
-- order urls live on as content.offers; the pre-image is the pg_dump taken
-- immediately before this ran, recorded in the slice checkpoint.
--
-- site.menu_item_platforms carried a dish's per-platform price + order url. The
-- content.* twin is a content.offers row per channel, plus the `order_platform`
-- collection whose content.storefronts sidecar re-pairs an offer with the
-- platform it belongs to (ManualMenuItems::platforms() matches the offer URL's
-- host against the storefront URL — MenuFetchJob::syncOrderPlatforms writes it
-- from site.menu_platform_links, which SURVIVES).
--
-- No IF EXISTS — fail loudly on schema drift rather than silently skipping.

BEGIN;

DROP TABLE site.menu_item_platforms;

COMMIT;
