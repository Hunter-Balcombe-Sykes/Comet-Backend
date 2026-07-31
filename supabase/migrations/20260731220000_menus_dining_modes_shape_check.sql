-- #DINT-4 — site.menus.dining_modes has no shape enforcement beyond app-layer
-- normalization. The column's comment documents an array-of-strings shape
-- (e.g. ["DELIVERY","PICKUP"]) and UberEatsMenuDriver::diningModes() already
-- normalizes to list<string>|null, tolerating both the [{mode, isAvailable}]
-- and bare string-list API shapes. But that normalization is PHP-side only:
-- nothing at the DB layer stops a non-array value reaching the column via a
-- future direct-DB write, a manual fix, or a second writer. The single current
-- reader (MenuController / the public menu payload) would then have to defend
-- itself, and any future consumer would have to independently do the same.
--
-- STRUCTURE ONLY, DELIBERATELY NOT CONTENT. The finding originally proposed
-- constraining the element values to a fixed list; that was rejected on
-- verification. UberEatsMenuDriver::diningModes() passes through whatever
-- mode-name strings Uber Eats' supportedDiningModes API returns, with no
-- app-side vocabulary of its own. An enum CHECK would therefore reject a
-- legitimate new dining-mode string the moment Uber Eats introduced one, and
-- the failure would surface as a silently-failing scrape, not as a clear
-- error. The vocabulary is externally controlled, so only the outer shape is
-- ours to assert.
--
-- NULL stays legal — DoorDash exposes no dining modes at all, so NULL is the
-- documented "unavailable" value, not a gap.
--
-- NOT VALID here, VALIDATE in 20260731220001, per the repo's established file
-- pair (CONVENTIONS.md §2): a plain ADD CONSTRAINT ... CHECK validates every
-- existing row under ACCESS EXCLUSIVE and fails the migration-safety guard.
-- Verified against dev (glncumufgaqcmqhzwrxm) on 2026-07-31 before writing:
-- 14 rows — 10 NULL, 4 array, 0 violations — so the VALIDATE is a formality
-- on today's data, but the split is what keeps the lock window short on a
-- table that will not stay 14 rows.
--
-- ROLLBACK: ALTER TABLE "site"."menus"
--               DROP CONSTRAINT IF EXISTS "menus_dining_modes_is_array";
--           Consequence: a non-array value can again be written to
--           dining_modes by any path that bypasses UberEatsMenuDriver, and the
--           next reader crashes trying to iterate it (#DINT-4).

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."menus"
    DROP CONSTRAINT IF EXISTS "menus_dining_modes_is_array";

ALTER TABLE "site"."menus"
    ADD CONSTRAINT "menus_dining_modes_is_array"
    CHECK ("dining_modes" IS NULL OR "jsonb_typeof"("dining_modes") = 'array')
    NOT VALID;

COMMIT;
