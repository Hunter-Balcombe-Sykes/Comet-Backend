-- RECOVERED, not authored. This migration was applied to the dev ref directly
-- (Supabase MCP `apply_migration` writes the ledger row without ever writing a
-- file), so `supabase_migrations.schema_migrations` carried version
-- 20260903170000 while no file existed in any git ref. That mismatch makes
-- `supabase db push` refuse EVERY subsequent migration with
-- LegacyDbPushMissingLocalError — it blocked this branch's four, and would have
-- blocked every other lane's too.
--
-- The CLI suggests `migration repair --status reverted 20260903170000` for this
-- error. That would be wrong: the DDL below IS applied on dev, and marking it
-- reverted would tell the ledger a lie that the next from-zero apply would then
-- act on. Recovering the file is the fix; repairing the ledger is not.
--
-- Reconstructed from the live dev schema on 2026-09-03, not from memory: the
-- column types, nullability and both COMMENT strings below were read back off
-- `core.pre_account_builds` verbatim, which is why the comments read in the
-- originating lane's voice rather than this one's. Behaviour on dev is a
-- guaranteed no-op — every statement is IF NOT EXISTS and every object already
-- exists. What it actually buys is a from-zero apply that reaches the same
-- schema, and a `db push` that works again.
--
-- If the lane that wrote this still has the original, THAT file wins — replace
-- this one wholesale rather than reconciling the two by hand.
--
-- ROLLBACK: ALTER TABLE "core"."pre_account_builds" DROP COLUMN IF EXISTS "settled_at";
--           ALTER TABLE "core"."pre_account_builds" DROP COLUMN IF EXISTS "setup_stalled_at";
--           (drops the stamps and the history they carry; the sweep index in
--           20260903170001 reads both, so reverse that file first or the DROP
--           takes the index with it.)

ALTER TABLE "core"."pre_account_builds"
  ADD COLUMN IF NOT EXISTS "settled_at" timestamptz NULL;

ALTER TABLE "core"."pre_account_builds"
  ADD COLUMN IF NOT EXISTS "setup_stalled_at" timestamptz NULL;

COMMENT ON COLUMN "core"."pre_account_builds"."settled_at" IS
    'The setup cascade genuinely finished (BuildProgressReader OUTCOME_SETTLED). Stamped once by builds:settle-sweep.';

COMMENT ON COLUMN "core"."pre_account_builds"."setup_stalled_at" IS
    'Terminal without settling -- hit the 10-minute ceiling or failed. No email is ever sent for these; builds:stalled reads this.';
