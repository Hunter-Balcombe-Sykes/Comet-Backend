-- ROLLBACK: ALTER TABLE site.sites DROP COLUMN setup_step, DROP COLUMN setup_completed_at;
-- Setup dialog state (A.9, decision 7). setup_step records the pass being
-- shown so a closed dialog reopens where it left; setup_completed_at is the
-- one "done" bit /me exposes for the open decision. The completed backfill
-- for existing sites lives in 20260902220001 (CONVENTIONS.md §5 — no DML in
-- the DDL transaction on a hot table).
BEGIN;
SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';
ALTER TABLE "site"."sites" ADD COLUMN "setup_step" text;
ALTER TABLE "site"."sites" ADD COLUMN "setup_completed_at" timestamp with time zone;
COMMIT;
