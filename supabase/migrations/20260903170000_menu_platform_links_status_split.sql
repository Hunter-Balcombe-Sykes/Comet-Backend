-- ROLLBACK: ALTER TABLE site.menu_platform_links DROP CONSTRAINT IF EXISTS menu_platform_links_status_check;
--           ALTER TABLE site.menu_platform_links ADD CONSTRAINT menu_platform_links_status_check
--             CHECK (status IS NULL OR status IN ('pending', 'ok', 'unavailable'));
--
-- Splits the single flattened 'unavailable' outcome into the three distinct
-- failures MenuApifyScraper::mapResponse()/attemptScrape() already tell
-- apart internally, so the product can say WHY a platform's menu is empty
-- instead of one blank "unavailable":
--   'blocked'     — the Apify actor run itself was not successful (bot-
--                    blocked / errored).
--   'not_found'   — the actor ran successfully but the dataset came back
--                    empty — no store was found at that URL.
--   'empty_menu'  — the store mapped fine but its menu had zero categories
--                    (a real, currently-empty menu).
--
-- 'unavailable' STAYS in the allowed set — every existing row carries it,
-- and MenuFetchJob::writePlatformSyncStatus() keeps writing it as the
-- fallback whenever MenuApifyScraper::lastFailureReasons() has no entry for
-- a platform (a mocked scraper in tests, budget exhaustion, or the
-- transport=http driver lane, which never goes through the Apify code that
-- records a reason). So this migration needs no data backfill to be safe.
--
-- NOT VALID + a separate VALIDATE CONSTRAINT transaction (CONVENTIONS.md
-- §2): the table is populated (existing 'unavailable' rows), so a bare ADD
-- CONSTRAINT CHECK would scan the whole table under ACCESS EXCLUSIVE.
-- site.menu_platform_links is not one of the CONVENTIONS.md §8 hot tables,
-- so no lock/statement timeout is required here.

BEGIN;

ALTER TABLE site.menu_platform_links
    DROP CONSTRAINT IF EXISTS menu_platform_links_status_check;

ALTER TABLE site.menu_platform_links
    ADD CONSTRAINT menu_platform_links_status_check
    CHECK (status IS NULL OR status IN ('pending', 'ok', 'blocked', 'not_found', 'empty_menu', 'unavailable'))
    NOT VALID;

COMMIT;

BEGIN;

ALTER TABLE site.menu_platform_links
    VALIDATE CONSTRAINT menu_platform_links_status_check;

COMMIT;

COMMENT ON COLUMN site.menu_platform_links.status IS
    'Last per-platform scrape outcome. pending = queued, not yet scraped. ok = menu scraped and written. blocked = the Apify actor run was not successful (bot-blocked / errored). not_found = the actor ran successfully but returned no dataset item for the store URL. empty_menu = the store mapped fine but its menu had zero categories. unavailable = a failure outside the Apify lane with no specific reason recorded (mocked scraper, budget exhaustion, transport=http driver).';
