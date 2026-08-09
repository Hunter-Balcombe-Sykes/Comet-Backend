-- Backfill site.design_kits onto the surviving theme mode and font roster
-- (2026-08-06 design-kit simplification).
--
-- Runs BEFORE 20260806090001 drops the removed columns, so every row holding a
-- value we are about to delete is rewritten while the column still exists.
--
-- Two narrowings need data moved:
--   • theme_mode  — the roster collapses to 'bleach'. Everything else becomes
--     bleach. Note 'warm', 'dusk' and 'midnight' were only ever honoured by the
--     backend: the sitepage renderer has never known them and already fell back
--     to bleach, so those sites do not change appearance at all.
--   • typography_font_family — the roster narrows to four. Retired slugs land on
--     their nearest survivor, byte-identical to the map in
--     app/Services/Design/FontKeywordClassifier.php and the design system's
--     RETIRED_FONT_MIGRATION.
--
-- Dev counts at authoring time (36 rows total): theme_mode = 1 'warm', 1
-- 'bleach', 34 NULL; typography_font_family = 1 'geist', 2 'helvetica-neue',
-- 1 'monument-grotesk', 32 NULL. So this rewrites exactly two rows on dev.
--
-- Wrapped with timeouts rather than left bare (CONVENTIONS §5 prefers backfills
-- outside a transaction, but site.design_kits is on the hot-table list and the
-- guard requires a lock timeout there). Safe here because the table holds one
-- row per site, not a commerce-scale ledger: the whole rewrite is a handful of
-- rows against a short statement_timeout. Every statement is idempotent —
-- re-running is a no-op.
--
-- ROLLBACK: none possible. The pre-image is not retained; a row that said
-- 'dust' cannot be distinguished afterwards from one that always said 'bleach'.
-- The values are recoverable only from a database snapshot taken before this
-- migration ran.

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '30s';

UPDATE "site"."design_kits"
   SET "theme_mode" = 'bleach'
 WHERE "theme_mode" IS NOT NULL
   AND "theme_mode" <> 'bleach';

UPDATE "site"."design_kits"
   SET "typography_font_family" = 'helvetica-neue'
 WHERE "typography_font_family" IN ('geist', 'inter', 'helvetica-now');

UPDATE "site"."design_kits"
   SET "typography_font_family" = 'monument-grotesk'
 WHERE "typography_font_family" = 'general-sans';

COMMIT;
