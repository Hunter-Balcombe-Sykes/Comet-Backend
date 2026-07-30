-- 271-PRIV-1: site.item_slugs had no retention column and no purge job, so
-- is_current = false rows (retired slugs kept as 301 targets) accumulated
-- forever. retired_at is the boundary the prune job reads: NULL means "this
-- row is live", non-NULL means "demoted at this instant, delete it once
-- config('partna.item_slugs.retirement_days') has elapsed".
--
-- Nullable, no default, no CHECK. A CHECK tying retired_at to is_current
-- would be WRONG: ItemSlugAllocator::ensureCurrent() (the rename path)
-- inserts the incoming slug with is_current = false and retired_at NULL for
-- the instant before promote() flips it, so `is_current = false =>
-- retired_at IS NOT NULL` is false by construction.
--
-- Written by exactly one place: ItemSlugAllocator::demoteExcept(). Cleared by
-- exactly one place: ItemSlugAllocator::promote(), so a rename-back
-- reactivation cannot leave a live slug carrying a delete stamp.
--
-- ROLLBACK: ALTER TABLE "site"."item_slugs" DROP COLUMN IF EXISTS "retired_at";

ALTER TABLE "site"."item_slugs"
    ADD COLUMN IF NOT EXISTS "retired_at" timestamptz;

COMMENT ON COLUMN "site"."item_slugs"."retired_at" IS
    'Instant this slug was demoted from is_current (ItemSlugAllocator::demoteExcept). '
    'NULL = live. slugs:prune-retired hard-deletes rows past '
    'config(''partna.item_slugs.retirement_days'') days; ItemSlugAllocator::lookupCurrent() '
    'stops serving them as 301 aliases the moment the window lapses, before the nightly '
    'delete. Cleared back to NULL by promote() on rename-back reactivation. 271-PRIV-1.';
