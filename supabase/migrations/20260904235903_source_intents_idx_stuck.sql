-- Split out of 20260903220001 (guard:no-unsafe-migrations Check 6 /
-- CONVENTIONS.md §1 — a CONCURRENTLY statement must be alone in its file).
-- Recreates idx_source_intents_stuck under the widened predicate that admits
-- 'verifying', dropped by 20260903220001 under its old definition. Timestamped
-- after that file so the state_check CHECK constraint already admits
-- 'verifying' by the time this runs (ADD CONSTRAINT enforces new writes
-- immediately, even before VALIDATE catches up on existing rows).
--
-- ONE statement, alone in its file, on purpose — CONCURRENTLY cannot run in a
-- libpq pipeline, and the CLI pipelines any file with more than one statement
-- (SQLSTATE 25001).
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS "routing"."idx_source_intents_stuck";

CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_source_intents_stuck"
    ON "routing"."source_intents" ("state", "first_seen_at")
    WHERE (state IN ('proposed', 'verifying', 'blocked'));
