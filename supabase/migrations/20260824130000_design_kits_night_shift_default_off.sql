-- New signups default to night shift OFF (owner, 2026-08-24).
--
-- theme_night_shift_auto has always been nullable with no column default —
-- site.create_empty_design_kit() (the trigger fired on every new site.sites
-- row) inserts only site_id, so every new profile's row landed with NULL
-- here. NULL is not "off": DesignKitValidationRules' own header says NULL
-- means "unset, the pages app fills it in" — and it does, at
-- DESIGN_KIT_DEFAULTS.theme.nightShiftAuto in the monorepo's design-system
-- package, which resolves a NULL to `true`. So every unset profile — new and
-- existing alike — has been rendering with the 19:00–07:00 auto-reversal on.
--
-- SCOPED TO NEW SIGNUPS ONLY, deliberately: this sets the COLUMN default,
-- which Postgres only applies to rows inserted after the change. Existing
-- profiles keep their current NULL and keep resolving to the frontend's
-- `true` exactly as they do today — nothing about an existing site's render
-- changes. Only a site.sites row created from this migration onward gets an
-- explicit `false` written by the trigger's insert, which is what the pages
-- app then reads back instead of falling through to the frontend default.
--
-- Locking: SET DEFAULT is a catalog-only write — no table rewrite, no row
-- scan, ACCESS EXCLUSIVE held for microseconds even on a populated table.
-- site.design_kits is on the hot list regardless, hence the short
-- lock_timeout per CONVENTIONS.md §8.
--
-- ROLLBACK: ALTER TABLE site.design_kits ALTER COLUMN theme_night_shift_auto DROP DEFAULT;

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "site"."design_kits"
    ALTER COLUMN "theme_night_shift_auto" SET DEFAULT false;

COMMIT;
