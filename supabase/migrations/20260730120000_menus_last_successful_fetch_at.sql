-- LIFE-12: a failure-episode boundary for the menu-scrape health notification.
-- PlatformHealthNotifier::menuScrapeFailed() keys its dedupe key on "the last
-- time this menu genuinely succeeded", so a user who recovers and then fails
-- again is told a second time. last_fetched_at CANNOT serve that purpose: it has
-- three writers -- MenuFetchJob (success AND soft-unavailable branches),
-- MenuContentController::resolveMenu() (every manual dish add/edit) and
-- MenuScanApplier::resolveMenu() (every menu-photo scan apply) -- so a manual
-- edit mid-outage would advance the episode with nothing having been fixed.
-- last_successful_fetch_at has exactly ONE writer: MenuFetchJob's fetch_status
-- = 'ok' branch. Nullable with no default, per the house rule for new columns;
-- deliberately NOT backfilled (see below).
--
-- to revert:
--   ALTER TABLE "site"."menus" DROP COLUMN "last_successful_fetch_at";

ALTER TABLE "site"."menus"
    ADD COLUMN IF NOT EXISTS "last_successful_fetch_at" timestamptz;

COMMENT ON COLUMN "site"."menus"."last_successful_fetch_at" IS
    'Advances only on a genuine successful scrape (MenuFetchJob, fetch_status = ''ok'' branch). '
    'LIFE-12: last_fetched_at has three writers (MenuFetchJob incl. its soft-unavailable branch, '
    'manual menu edits, photo-scan applies) so it cannot serve as a failure-episode boundary. '
    'NOT backfilled from last_fetched_at: that value may itself have come from a manual edit, '
    'which would seed this column with exactly the ambiguity it exists to escape. NULL simply '
    'means "no scrape has succeeded since this column shipped" and reads as episode ''never''.';
