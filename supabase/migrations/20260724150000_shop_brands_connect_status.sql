-- W9 — per-brand deferred-connect state for site.shop_brands.
--
-- Shop is the only platform where ONE site.platform_connections row fans out to
-- many content rows (MAX_BRANDS = 5), and its payload is frozen to the FOUND-25
-- marker {"storage":"relational"} — so the connection row's last_refresh_status
-- cannot express "brand A pending, brand B ready." The status has to live on the
-- brand.
--
--   connect_status  NULL     = settled / ready (the overwhelming majority, and
--                              the state EVERY pre-existing row is in — hence
--                              NULL rather than a 'ready' sentinel, and hence
--                              no DB default, per the house rule for new columns)
--                   'pending' = ShopBrandConnectJob is in flight for this brand
--                   'failed'  = the job reached a terminal failure. NOTE this
--                              does NOT mean the brand is unusable: brand_id,
--                              provider, url and source_url are all truthful at
--                              202 time under design path (c), so the picker and
--                              the public render both work. Only the display
--                              profile (name/currency/favicon/logo) is missing —
--                              exactly the state today's SYNCHRONOUS path already
--                              reaches when a store's homepage fetch fails.
--   connect_error   the displayable sentence the poll endpoint returns verbatim.
--                   Only ever one of the two shared infrastructure strings from
--                   DefersBespokeConnect; never scraper internals.
--
-- App-side vocabulary source of truth: App\Models\Core\Site\ShopBrand::CONNECT_STATUSES.
-- Kept in lockstep by tests/Feature/Database/ConstraintVocabularyLockstepTest.php.
--
-- site.shop_brands is not a HOT_TABLE, but the NOT VALID -> VALIDATE split
-- (CONVENTIONS.md §2) still applies, so this copies the two-window shape of
-- 20260720100200_shop_brands_mode_checks.sql verbatim.
--
-- Dev census (glncumufgaqcmqhzwrxm): every existing row predates the column and
-- is therefore NULL — VALIDATE is a trivially clean pass.

-- Window 1: add the columns and the CHECK in NOT VALID form.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.shop_brands
    ADD COLUMN IF NOT EXISTS connect_status text,
    ADD COLUMN IF NOT EXISTS connect_error  text;

-- Drop-then-add so a replay of this file is idempotent (same reasoning as
-- Window 3 of 20260701190000, which MigrationTransactionBoundaryTest pins).
ALTER TABLE site.shop_brands
    DROP CONSTRAINT IF EXISTS shop_brands_connect_status_check;

ALTER TABLE site.shop_brands
    ADD CONSTRAINT shop_brands_connect_status_check
    CHECK (connect_status IS NULL OR connect_status IN ('pending', 'failed')) NOT VALID;

COMMIT;

-- Window 2: validate in a second transaction.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.shop_brands VALIDATE CONSTRAINT shop_brands_connect_status_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.shop_brands
--     DROP CONSTRAINT IF EXISTS shop_brands_connect_status_check;
-- ALTER TABLE site.shop_brands
--     DROP COLUMN IF EXISTS connect_status,
--     DROP COLUMN IF EXISTS connect_error;
-- COMMIT;
