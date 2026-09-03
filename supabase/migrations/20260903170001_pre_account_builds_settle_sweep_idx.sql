-- RECOVERED, not authored — same provenance as 20260903170000: applied straight
-- to the dev ref with no file behind it, which is what made `supabase db push`
-- refuse every later migration. See that file's header for why the ledger is
-- repaired by restoring the file rather than by `migration repair`.
--
-- Reconstructed from dev's live `pg_indexes.indexdef` on 2026-09-03, so the
-- column, the predicate and the index name are the real ones rather than a
-- plausible guess. Applying it to dev is a no-op; it exists so a from-zero
-- apply lands the same index.
--
-- What the index is for: `builds:settle-sweep` scans for builds that have
-- neither settled nor stalled, oldest first. The partial predicate is the whole
-- point — the unsettled set is the small live tail, so the index stays tiny
-- while the table grows, and a row leaves it for good the moment either stamp
-- is written. Both predicate columns come from 20260903170000, so that file
-- MUST stay ordered ahead of this one.
--
-- Single statement, alone in its file, so it is safe to apply from zero even
-- with CONCURRENTLY — supabase/migrations/CONVENTIONS.md §1.
--
-- CONCURRENTLY added 2026-09-04 (guard:no-unsafe-migrations Check 1) — dev's
-- live index was built without it when this was applied ad hoc (see the
-- recovery story above), but CONCURRENTLY only changes how the index is
-- built, not its resulting definition, so this stays a no-op against dev.
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS "core"."pre_account_builds_settle_sweep_idx";

CREATE INDEX CONCURRENTLY IF NOT EXISTS "pre_account_builds_settle_sweep_idx"
    ON "core"."pre_account_builds" ("created_at")
    WHERE ("settled_at" IS NULL AND "setup_stalled_at" IS NULL);
