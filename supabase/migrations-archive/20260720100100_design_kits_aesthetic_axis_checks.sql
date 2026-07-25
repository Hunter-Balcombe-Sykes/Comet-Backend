-- DINT-101 — CHECK constraints for the two SELECTION-axis design-kit columns
-- added by 20260714220000_add_aesthetic_axes.sql: typography_tracking and
-- theme_contrast. Both are already validated at the HTTP layer
-- (DesignKitValidationRules: 'in:tight,normal,wide' / 'in:soft,normal,stark'),
-- so this is not a live user-facing gap — it closes the second door for
-- non-HTTP write paths (direct DB fixes, restore/import tooling, seeders,
-- future admin scripts) that bypass the Form Request.
--
-- Both columns are NULLable and NULL is the documented "unset" state (defaults
-- are filled code-side by @partnaau/design-system/design-kit at read time,
-- never a DB-level default — see the site-architecture-system rules), so each
-- CHECK explicitly allows NULL alongside its 3-value vocabulary.
--
-- weight_heading (added by the same 20260714220000 migration) is a VALUE axis,
-- not a SELECTION axis — it holds a free-form weight token, not a closed
-- enum — so it is deliberately left unconstrained here.
--
-- site.design_kits is a HOT_TABLE (guard-no-unsafe-migrations.php Check 5) —
-- read on every public sitepage payload build — so both windows carry a
-- lock_timeout.
--
-- Dev census (glncumufgaqcmqhzwrxm, 2026-07-20): 20 rows — typography_tracking
-- is NULL x19 + 'normal' x1; theme_contrast is NULL x19 + 'normal' x1. VALIDATE
-- is a clean pass on both.
--
-- Each ADD CONSTRAINT clause carries its own NOT VALID (guard Check 3 matches
-- per-clause, terminating at the next ADD CONSTRAINT or the statement end).

-- Window 1: add both CHECKs in NOT VALID form.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.design_kits
    ADD CONSTRAINT design_kits_typography_tracking_check
    CHECK (typography_tracking IS NULL OR typography_tracking IN ('tight', 'normal', 'wide')) NOT VALID,
    ADD CONSTRAINT design_kits_theme_contrast_check
    CHECK (theme_contrast IS NULL OR theme_contrast IN ('soft', 'normal', 'stark')) NOT VALID;

COMMIT;

-- Window 2: validate both in a second transaction.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.design_kits VALIDATE CONSTRAINT design_kits_typography_tracking_check;
ALTER TABLE site.design_kits VALIDATE CONSTRAINT design_kits_theme_contrast_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.design_kits
--     DROP CONSTRAINT IF EXISTS design_kits_typography_tracking_check,
--     DROP CONSTRAINT IF EXISTS design_kits_theme_contrast_check;
-- COMMIT;
