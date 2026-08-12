-- One CURRENT slug per content item, enforced by the database. (1 of 2 —
-- the non-unique predecessor is dropped in …040001; this file creates the
-- replacement first so the lookup it serves is never absent.)
--
-- WHY. ContentItemSlugAllocator's own docblock opens with "There is NO
-- `one_current` partial unique index here. Two `is_current` rows ...", and
-- every writer since has been careful because of it. That care is the only
-- thing holding the invariant up: promote() and ProjectionWriter::moveSlugs()
-- both retire-then-promote in the right order, but nothing stops a third
-- writer, or two concurrent runs of either, from leaving an item with two
-- current slugs. A second current slug is not cosmetic — lookupCurrent()
-- picks one arbitrarily, so the item's public URL would flip between two
-- values run to run, and the 301 lane would disagree with the page.
--
-- site.item_slugs has carried exactly this constraint since the baseline
-- (`item_slugs_one_current`). This is the content.* sibling, keyed on item_id
-- alone because content.items.id is already globally unique.
--
-- SAFE TO APPLY. content.item_slugs holds 0 rows on dev and on production —
-- nothing minted into it until ProjectionWriter gained its minter, and the
-- backfill has not run. Applied BEFORE content:backfill-item-slugs precisely
-- so the table is empty and the index cannot fail on legacy duplicates.
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS "content"."idx_content_item_slugs_one_current";

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_content_item_slugs_one_current"
    ON "content"."item_slugs" ("item_id")
    WHERE "is_current";
