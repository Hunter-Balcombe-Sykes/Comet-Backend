-- Re-add routing.source_intents.identifier_label — 2026-08-26.
--
-- History, because the ledger looks odd without it:
--   20260824120000  ADD   (b5eecd10e — committed after the fact to reconcile a
--                          column applied to dev via MCP with no file, which
--                          had hard-blocked `supabase db push` for everyone)
--   20260825200000  DROP  (98156f483 — the column sat valueless with nothing
--                          referencing it, so it was retired)
--   20260826140000  ADD   (this file — the code half now exists)
--
-- The 2026-08-25 drop was correct on the evidence available: 67 rows, 0 with a
-- value, 0 references in app/. What was NOT visible is that the writer had been
-- built and never committed anywhere — no branch, no reflog, no unreachable
-- object carries it (searched: all 27,119 blobs in the repo). So the column is
-- re-added here alongside a writer that is actually in the tree:
-- StoreBrandSeeder attaches the probe's shop_name to the Placement, and
-- SourceReconciler persists it.
--
-- ⚠️ Do NOT "tidy" this by deleting either earlier file. Both versions are
-- recorded in supabase_migrations.schema_migrations; removing one re-orphans
-- its ledger row and re-breaks db push. ADD -> DROP -> ADD also replays
-- correctly from zero (fresh-reset.sh, a prod cutover) and ends with the
-- column present, which is what dev will be.
--
-- Nullable with no default and no backfill: every existing row legitimately
-- has no label (the myshopify.com host-detector lane never fetches a name at
-- all, and stays NULL by design). Readers coalesce.
--
-- ROLLBACK: ALTER TABLE routing.source_intents
--             DROP COLUMN IF EXISTS identifier_label;

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "routing"."source_intents"
    ADD COLUMN IF NOT EXISTS "identifier_label" text;

COMMENT ON COLUMN "routing"."source_intents"."identifier_label" IS
    'Human-readable name for identifier, when the lane that recorded the intent carried one (e.g. a probe''s shop_name). NULL is normal — regex-only detector lanes carry no name.';

COMMIT;
