-- routing.source_intents.identifier_label — the human-readable label for an
-- intent's identifier (the raw identifier is an opaque handle/slug/id).
--
-- ⚠️ PROVENANCE, read before assuming intent. This file was written on
-- 2026-08-25 to RECONCILE a ledger/tree mismatch, not to introduce the column.
-- The column was already applied to dev (glncumufgaqcmqhzwrxm) on 2026-08-24
-- via the Supabase MCP, which recorded version 20260824120000 in
-- supabase_migrations.schema_migrations — but the migration file itself was
-- never committed to any branch. That left a ledger row pointing at nothing,
-- which is a HARD `supabase db push` error for everyone ("Remote migration
-- versions not found in local migrations directory"), and it meant a from-zero
-- apply (scripts/db/fresh-reset.sh, or a future prod cutover) would silently
-- NOT have this column while dev did.
--
-- The version is deliberately 20260824120000 so it matches the ledger row that
-- already exists — no ledger edit is needed and `db push` will correctly treat
-- it as applied.
--
-- The DDL below was reconstructed from the LIVE dev schema, not from the
-- original author's file (which does not exist anywhere): text, NULLABLE, no
-- default, no index, no constraint, no column comment. Verified 2026-08-25.
--
-- At the time of writing NOTHING reads this column — no app/, config/, routes/,
-- database/ or tests/ reference — and 0 rows carry a value. If it turns out to
-- have been an abandoned experiment, dropping it is the ROLLBACK below; that is
-- a deliberate decision for its author to make, and reconciling the tree to the
-- database is not that decision.
--
-- ROLLBACK: ALTER TABLE routing.source_intents
--             DROP COLUMN IF EXISTS identifier_label;

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "routing"."source_intents"
    ADD COLUMN IF NOT EXISTS "identifier_label" text;

COMMIT;
