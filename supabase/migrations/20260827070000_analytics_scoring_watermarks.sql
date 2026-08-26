-- The scoring sweep's persisted last-successful-run watermark.
-- Plan: partna-monorepo docs/overnight-run-2026-08-27/01-smart-scoring.md
-- (step 6) — the "missed-tick gap" routes/console.php has documented since
-- 2026-07-20: the periodic analytics:compute-popularity sweep scoped its
-- site set to a FIXED 60-minute lookback, so events landing during a >45min
-- scheduler outage fell outside every subsequent run's window; a site whose
-- last-ever activity landed in such a gap stayed unranked until unrelated
-- traffic scoped it back in.
--
-- One row (id = 'popularity'), no per-site grain: the sweep reads
-- last_completed_at and widens its lookback to cover everything since the
-- previous successful run (plus slack, capped at 7 days), then advances the
-- row on success. --site and --dry-run runs never touch it.
--
-- ROLLBACK:
--   DROP TABLE IF EXISTS "analytics"."scoring_watermarks";

CREATE TABLE IF NOT EXISTS "analytics"."scoring_watermarks" (
    "id" text PRIMARY KEY,
    "last_completed_at" timestamptz NOT NULL,
    "updated_at" timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE "analytics"."scoring_watermarks" ENABLE ROW LEVEL SECURITY;
