-- Drop routing.source_intents.identifier_label — owner decision, 2026-08-25.
--
-- The column was applied to dev on 2026-08-24 via the Supabase MCP and its
-- migration file was never committed, which hard-blocked `supabase db push`
-- for everyone. 20260824120000_source_intents_identifier_label.sql (b5eecd10e)
-- reconciled that by committing the ADD from the live schema; this migration
-- then retires the column, which is the decision that file's header deferred
-- to its author.
--
-- ⚠️ Do NOT "tidy up" by deleting the ADD migration instead. Its version is the
-- one already recorded in supabase_migrations.schema_migrations, so removing
-- the file would re-orphan that ledger row and re-break db push. ADD-then-DROP
-- is also what keeps a from-zero apply correct: fresh-reset.sh / a prod cutover
-- replays both in filename order and ends with the column absent, matching dev.
--
-- Safe to drop, verified against dev immediately before writing this:
--   67 rows in routing.source_intents, 0 with identifier_label NOT NULL
--   0 pg_depend entries on the column; 0 views referencing it
--   0 index, constraint or column comment
--   0 references in app/, config/, routes/, database/, tests/, scripts/
--
-- ROLLBACK: ALTER TABLE routing.source_intents
--             ADD COLUMN IF NOT EXISTS identifier_label text;
--           (re-adding is free — the column never held a value)

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "routing"."source_intents"
    DROP COLUMN IF EXISTS "identifier_label";

COMMIT;
