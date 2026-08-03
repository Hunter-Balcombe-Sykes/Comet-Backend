-- D2b (2026-07-31 Redis-down drill, Finding 7): core.users.handle_lc is the
-- column every public read resolves on -- SiteResolver, the public-profile
-- cache key, SyncSubdomainToKvJob -- while handle is what the dashboard shows.
-- Nothing in the database tied them together: no CHECK, no INSERT trigger, and
-- the two existing triggers fire only ON UPDATE OF handle. A write path that
-- set handle without handle_lc therefore made a site publicly unreachable while
-- looking perfectly correct in the DB and to every dashboard read. That is what
-- cost real debugging time during the drill's ARRANGE step.
--
-- Part (a) -- App\Models\Core\User\User::setHandleAttribute(), shipped in
-- c575ac2d -- fixes the model layer. This is the backstop for every write that
-- bypasses Eloquent: a DB::table()->update(), a manual SQL fix, a seeder.
--
-- WHY btrim IS NOT IN THE PREDICATE. The constraint is deliberately the plain
-- `handle_lc = lower(handle)` rather than `lower(btrim(handle))`. The model
-- trims BOTH columns (part a), and every write path validates
-- regex:/^[a-z0-9_-]+$/i (BootstrapRequest, ReclaimHandleRequest, the rename
-- flow), which cannot match whitespace. Folding btrim in would let a
-- whitespace-carrying handle satisfy the constraint, which is the opposite of
-- what this is for -- such a row is a bug, and the strict form catches it.
--
-- Verified against real data immediately before writing (2026-08-03):
--   dev  (glncumufgaqcmqhzwrxm): 37 rows, 0 mismatched, 0 untrimmed, 0 nulls
--   prod (edplucmvkcnokyygxqsb): 0 rows
-- Both columns are NOT NULL on both refs, so there is no "NULL passes a CHECK"
-- hole to reason about. VALIDATE (next file) cannot fail on data.
--
-- NOT VALID here, VALIDATE in a SEPARATE transaction per CONVENTIONS.md 2
-- (guard Check 8): bundling them takes ACCESS EXCLUSIVE for the full table
-- scan, whereas a split lets VALIDATE run under SHARE UPDATE EXCLUSIVE while
-- reads and writes continue.
--
-- ROLLBACK: ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_handle_lc_matches_handle;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "core"."users"
    ADD CONSTRAINT "users_handle_lc_matches_handle"
    CHECK ("handle_lc" = lower("handle")) NOT VALID;

COMMIT;
