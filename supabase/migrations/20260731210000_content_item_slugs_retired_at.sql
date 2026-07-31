-- content.item_slugs has the same is_current/created_at shape as
-- site.item_slugs (271-PRIV-1) and the same non-partial unique on
-- (user_id, slug), but it is NOT in the same situation: nothing mints a row
-- into this table today. The only writer anywhere in app/ is
-- ItemMerger::moveSlugs() (app/Services/Content/ItemMerger.php), which only
-- MOVES existing rows between items and flips is_current -- there is no
-- allocator, and no read path builds a 301/alias payload from this table.
--
-- No backfill migration accompanies this one. Unlike site.item_slugs, there
-- is nothing to backfill: the table has zero rows in dev (content.items has
-- 395), because nothing has ever minted here. A backfill of an empty table
-- is noise.
--
-- Nullable, no default, no CHECK -- same reasoning as
-- site.item_slugs.retired_at's migration: a CHECK tying retired_at to
-- is_current would assume an allocator's insert-then-promote sequencing that
-- does not exist for this table.
--
-- ROLLBACK: ALTER TABLE "content"."item_slugs" DROP COLUMN IF EXISTS "retired_at";

ALTER TABLE "content"."item_slugs"
    ADD COLUMN IF NOT EXISTS "retired_at" timestamptz;

COMMENT ON COLUMN "content"."item_slugs"."retired_at" IS
    'Retirement stamp, mirroring site.item_slugs.retired_at (271-PRIV-1). '
    'Written by exactly one place today: ItemMerger::moveSlugs() '
    '(app/Services/Content/ItemMerger.php), which stamps it on every slug row '
    'it moves off a discarded item and clears it back to NULL on the one row '
    'promoted to is_current on the survivor. There is no allocator and no '
    'prune predicate reads this column for retention on its own -- '
    'slugs:prune-retired sweeps it as a second table alongside site.item_slugs, '
    'not as a standalone retention job. TRAP: anyone who later writes a '
    'minter/allocator for content.item_slugs (same table shape, no allocator '
    'today -- see the sibling warning at app/Services/Site/ItemSlugAllocator.php:27) '
    'inherits the obligation to stamp retired_at on every retirement or this '
    'column silently stops meaning anything.';
