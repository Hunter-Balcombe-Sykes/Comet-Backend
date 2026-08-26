-- Per-item ordering deep links + platform attribution on offers.
-- Plan: docs/2026-08-26-menu-item-deep-links-and-cleanup-plan.md (B1).
--
-- Three nullable columns on content.offers, written by
-- MenuProjectionMapper on the per-platform offers only (the three
-- aggregate base/pickup/delivery offers keep all three NULL):
--
--   platform     — menu-registry slug ('square' | 'uber-eats' |
--                  'doordash') labelling which ordering platform the
--                  offer belongs to. Replaces the read-time host-matching
--                  heuristics (ManualMenuItems::offerBelongsTo,
--                  ItemLinkRules::platformForUrl) that today re-derive
--                  the label the projection dropped.
--   item_url     — the per-item deep link (opens that exact dish on the
--                  platform), mode-agnostic. `url` stays the mode-typed
--                  STORE link; an offer never fabricates an item link.
--   external_ref — the platform's own item id (Uber itemUuid, DoorDash
--                  item_id, Square catalog id). Generalizes the retired
--                  dd_external_id and makes cross-scrape re-matching
--                  exact instead of name-derived.
--
-- Sold-out needs no new column: the existing `availability` column takes
-- the slice-5a in_stock spelling for per-platform offers.
--
-- No new index: every read path reaches offers through idx_offers_item
-- (item_id); platform/item_url are row attributes filtered in memory on
-- per-item result sets.
--
-- ROLLBACK:
--   ALTER TABLE "content"."offers" DROP COLUMN IF EXISTS "platform";
--   ALTER TABLE "content"."offers" DROP COLUMN IF EXISTS "item_url";
--   ALTER TABLE "content"."offers" DROP COLUMN IF EXISTS "external_ref";

BEGIN;

ALTER TABLE "content"."offers"
    ADD COLUMN IF NOT EXISTS "platform" text NULL,
    ADD COLUMN IF NOT EXISTS "item_url" text NULL,
    ADD COLUMN IF NOT EXISTS "external_ref" text NULL;

COMMIT;
