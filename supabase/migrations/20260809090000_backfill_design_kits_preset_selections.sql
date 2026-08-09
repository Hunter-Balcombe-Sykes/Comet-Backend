-- Re-express the four preset axes of site.design_kits as SELECTIONS rather
-- than raw CSS values (2026-08-09 design-kit go-live, brief §8.2).
--
-- Runs BEFORE 20260809090001 drops the legacy columns, because it READS them.
-- Ordering is load-bearing: once the drop has run, the source values are gone
-- and this mapping is unrecoverable except from a snapshot.
--
-- ─── WHY THIS COVERS FOUR AXES, NOT ONE ────────────────────────────────────
--
-- The first cut of this migration touched only `border_thickness`, on the
-- reasoning that it was "the one surviving column whose value vocabulary
-- changes" — the other three being NEW columns with nothing to convert.
--
-- That is wrong, and it cost real data on the dev ref before it was caught.
-- `corners`, `text_size` and `spacing` are not new axes; they are NARROWINGS
-- of axes that already existed:
--
--     border_radius   →  corners      ('default' 0.5rem | 'rounded' 1rem)
--     text_*          →  text_size    ('small' | 'medium' | 'large')
--     space_*         →  spacing      ('default' | 'spacious')
--     border_thickness→  border_thickness  ('default' 1px | 'none' 0)
--
-- A site that had explicitly set border_radius = '1.5rem' HAD made a choice.
-- Dropping the column without mapping it forward silently reverts that site to
-- the default rung — no error, no log line, just a site that quietly looks
-- different. Preserving one axis and discarding three is not a decision; it is
-- an oversight, and it is exactly the silent-failure class this go-live has
-- been hunting.
--
-- ─── THE MAPPING ───────────────────────────────────────────────────────────
--
-- Each legacy value is snapped to the NEAREST surviving rung, measured in rem.
-- Rungs come from packages/design-system/src/design-kit/presets.ts:
--
--   corners     0.5rem | 1rem              → midpoint 0.75rem
--   text_size   p: 0.75 | 0.85 | 0.95rem   → midpoints 0.8 / 0.9rem
--   spacing     gap.md: 0.6 | 0.65rem      → midpoint 0.625rem
--   thickness   1px | 0                    → any non-zero length is 'default'
--
-- Ties go to the BASE rung ('default'), the conservative direction: an
-- ambiguous site lands on the same rendering as an untouched one.
--
-- text_size reads `text_body` and spacing reads `space_regular` — one
-- representative member each. The legacy families moved together (a site that
-- set text_body also set text_h1, text_display, …), so the representative is
-- sufficient and avoids averaging across members that were free to disagree.
--
-- NULL STAYS NULL throughout. Null has always meant "use the package default",
-- and the package default IS the base rung. Writing 'default' over every NULL
-- row would make DesignRationaleService report a manual override on every site
-- that never touched the control. A row that carried an explicit legacy value
-- DOES get an explicit selection written, including when it maps to 'default'
-- — that site really did choose something.
--
-- ─── ACCEPTED LOSSES (narrowing, not bugs) ─────────────────────────────────
--
--   • Square corners are no longer expressible. border_radius = '0' maps to
--     'default' (0.5rem) because the two-rung control has no 0. One dev site
--     (019e5c37) is affected. If square matters as a product choice it needs a
--     third rung, not a migration change.
--   • A '0.5px' hairline becomes a 1px border — `none` is a real border-free
--     choice, not a hairline (presets.ts).
--   • '1px' and '2px' both become 'default'; the original length is not
--     retained and cannot be told apart afterwards.
--
-- ─── SAFETY ────────────────────────────────────────────────────────────────
--
-- Every statement is guarded on the legacy column still existing, so this is a
-- no-op (not an error) if it is ever replayed against an already-migrated
-- database. All statements are idempotent — the WHERE clauses exclude rows
-- already carrying a valid selection.
--
-- Wrapped with timeouts rather than left bare: CONVENTIONS §5 prefers backfills
-- outside a transaction, but site.design_kits is on the hot-table list and
-- Check 5 requires a lock timeout there. Safe because the table holds one row
-- per site, not a commerce-scale ledger.
--
-- ROLLBACK: none possible. Recoverable only from a snapshot taken before this
-- ran.

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '30s';

-- The three selection columns are created HERE, not in 20260809090001, because
-- this file writes to them and it runs first. 090001 keeps its own
-- `ADD COLUMN IF NOT EXISTS` clauses for the same three, which become no-ops
-- once this has run — that is deliberate: either file can be the one that
-- creates them, so neither depends on the other having done it. Catalog-only
-- (no default, no rewrite), so the lock is held for microseconds.
ALTER TABLE "site"."design_kits"
    ADD COLUMN IF NOT EXISTS "text_size" "text",
    ADD COLUMN IF NOT EXISTS "spacing" "text",
    ADD COLUMN IF NOT EXISTS "corners" "text";

-- Legacy lengths are text ('0.85rem', '12px', '0'). Normalise to a rem number;
-- anything unparseable yields NULL and is left alone rather than guessed at.
CREATE OR REPLACE FUNCTION pg_temp.dk_rem(v text) RETURNS numeric AS $$
  SELECT CASE
    WHEN v IS NULL THEN NULL
    WHEN v ~ '^\s*[0-9]*\.?[0-9]+\s*rem\s*$'
      THEN (regexp_replace(v, '[^0-9.]', '', 'g'))::numeric
    WHEN v ~ '^\s*[0-9]*\.?[0-9]+\s*px\s*$'
      THEN (regexp_replace(v, '[^0-9.]', '', 'g'))::numeric / 16
    WHEN v ~ '^\s*[0-9]*\.?[0-9]+\s*$'
      THEN v::numeric
    ELSE NULL
  END;
$$ LANGUAGE sql IMMUTABLE;

-- ── border_thickness: length → 'default' | 'none' ─────────────────────────
UPDATE "site"."design_kits"
   SET "border_thickness" = 'none'
 WHERE "border_thickness" IN ('0', '0px', '0rem', '0em', 'none');

UPDATE "site"."design_kits"
   SET "border_thickness" = 'default'
 WHERE "border_thickness" IS NOT NULL
   AND "border_thickness" NOT IN ('default', 'none');

-- ── border_radius → corners ───────────────────────────────────────────────
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'site' AND table_name = 'design_kits'
                AND column_name = 'border_radius') THEN
    EXECUTE $sql$
      UPDATE "site"."design_kits"
         SET "corners" = CASE
               WHEN pg_temp.dk_rem("border_radius") >= 0.75 THEN 'rounded'
               ELSE 'default'
             END
       WHERE "corners" IS NULL
         AND pg_temp.dk_rem("border_radius") IS NOT NULL
    $sql$;
  END IF;
END $$;

-- ── text_body → text_size ─────────────────────────────────────────────────
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'site' AND table_name = 'design_kits'
                AND column_name = 'text_body') THEN
    EXECUTE $sql$
      UPDATE "site"."design_kits"
         SET "text_size" = CASE
               WHEN pg_temp.dk_rem("text_body") < 0.8 THEN 'small'
               WHEN pg_temp.dk_rem("text_body") < 0.9 THEN 'medium'
               ELSE 'large'
             END
       WHERE "text_size" IS NULL
         AND pg_temp.dk_rem("text_body") IS NOT NULL
    $sql$;
  END IF;
END $$;

-- ── space_regular → spacing ───────────────────────────────────────────────
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'site' AND table_name = 'design_kits'
                AND column_name = 'space_regular') THEN
    EXECUTE $sql$
      UPDATE "site"."design_kits"
         SET "spacing" = CASE
               WHEN pg_temp.dk_rem("space_regular") <= 0.625 THEN 'default'
               ELSE 'spacious'
             END
       WHERE "spacing" IS NULL
         AND pg_temp.dk_rem("space_regular") IS NOT NULL
    $sql$;
  END IF;
END $$;

COMMIT;
