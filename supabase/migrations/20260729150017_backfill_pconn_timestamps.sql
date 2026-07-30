-- Step 2 of CONVENTIONS.md §3. Idempotent by construction (the WHERE clause
-- scopes to rows still needing it -- the guard #MIG-1 asks every backfill to
-- carry). Outside any transaction, per CONVENTIONS.md §5.
--
-- Expected to match 0 rows: prod site.platform_connections = 0 rows and dev =
-- 216 rows with 0 NULLs in either column (verified 2026-07-29). Left in because
-- it is the only thing standing between one stray explicit-NULL insert and a
-- failed VALIDATE in step 3.
--
-- COALESCE order is deliberate. created_at prefers the row's own updated_at
-- (the closest real evidence of when the row existed) over now(), which would
-- date a 2026-05 connection to the migration. updated_at prefers created_at for
-- the mirror reason. Only a row with BOTH columns NULL falls through to now().
--
-- NOTE: site.platform_connections carries BEFORE UPDATE trigger
-- set_timestamp_platform_connections (baseline_pilot.sql:3475) which calls
-- public.set_updated_at(). It will stamp updated_at = now() on every row this
-- statement touches -- which is exactly the value the second UPDATE wants
-- anyway, and it only ever touches rows that were NULL.
--
-- ROLLBACK: NONE for the VALUES. Nothing records which rows were NULL, and
--           the BEFORE UPDATE trigger set_timestamp_platform_connections
--           restamped updated_at on every row this touched. Reverting the
--           NOT NULL attribute is 20260729150018's ROLLBACK; the filled
--           timestamps stay.

UPDATE "site"."platform_connections"
   SET "created_at" = COALESCE("updated_at", now())
 WHERE "created_at" IS NULL;

UPDATE "site"."platform_connections"
   SET "updated_at" = COALESCE("created_at", now())
 WHERE "updated_at" IS NULL;
