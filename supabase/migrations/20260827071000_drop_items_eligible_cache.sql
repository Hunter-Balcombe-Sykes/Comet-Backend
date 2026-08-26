-- Drop content.items.eligible_cache — a vestigial column written as '[]' at
-- insert and never read anywhere (verified 2026-08-27: ProjectionWriter was
-- the only writer, always the empty array; zero filtering/branching readers;
-- the DSAR export selected it verbatim and now simply omits it). Flagged in
-- the smart-scoring plan's known-issues list; removed with it (step 7).
--
-- ROLLBACK:
--   ALTER TABLE "content"."items" ADD COLUMN "eligible_cache" jsonb NOT NULL DEFAULT '[]'::jsonb;

ALTER TABLE "content"."items" DROP COLUMN IF EXISTS "eligible_cache";
