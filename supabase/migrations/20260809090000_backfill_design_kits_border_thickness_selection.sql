-- Re-express site.design_kits.border_thickness as a SELECTION, not a length
-- (2026-08-09 design-kit go-live, brief §8.2).
--
-- Runs BEFORE 20260809090001 drops the other 52 columns. It touches only
-- border_thickness, the one surviving column whose VALUE VOCABULARY changes:
--
--   before   a CSS length written straight into --dk-border-thickness
--            ('1px', '0.5px', '2px', '0', …)
--   after    'default' | 'none', resolved through the design system's
--            THICKNESS_PRESETS ({default: '1px', none: '0'})
--
-- The mapping is the only one the two-value control can express:
--   • anything that renders no border  → 'none'   ('0', '0px', '0rem', 'none')
--   • any other non-null length        → 'default'
--   • NULL stays NULL. Null has always meant "use the package default", and
--     the package default IS 'default'. Writing 'default' over every NULL row
--     would make DesignRationaleService report a manual override on every
--     site that has never touched its border. Left alone deliberately.
--
-- A '0.5px' hairline becomes a 1px border. That is the intended narrowing:
-- `none` is a real border-free choice, not a hairline (presets.ts), and the
-- half-pixel rung stopped being offerable when the axis went to two values.
--
-- Dev counts at authoring time (36 rows): 34 NULL, 1 '1px', 1 '0.5px'. Both
-- non-null rows land on 'default'; nothing maps to 'none'. Production is held
-- by owner decision and has not been read.
--
-- Wrapped with timeouts rather than left bare (CONVENTIONS §5 prefers
-- backfills outside a transaction, but site.design_kits is on the hot-table
-- list and Check 5 requires a lock timeout there). Safe here because the table
-- holds one row per site, not a commerce-scale ledger. Both statements are
-- idempotent — re-running is a no-op.
--
-- ROLLBACK: none possible. '1px' and '2px' both become 'default'; the original
-- length is not retained and cannot be distinguished afterwards. Recoverable
-- only from a snapshot taken before this ran.

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '30s';

UPDATE "site"."design_kits"
   SET "border_thickness" = 'none'
 WHERE "border_thickness" IN ('0', '0px', '0rem', '0em', 'none');

UPDATE "site"."design_kits"
   SET "border_thickness" = 'default'
 WHERE "border_thickness" IS NOT NULL
   AND "border_thickness" NOT IN ('default', 'none');

COMMIT;
