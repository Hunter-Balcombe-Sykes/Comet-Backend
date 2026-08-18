-- 20260819002100_link_observations_user_fk.sql
--
-- Overnight 2026-08-18 R3, step 2 of 2. Give routing.link_observations.user_id
-- the ON DELETE CASCADE foreign key its three routing.* neighbours have had
-- since 20260727120000, so closing an account takes the observations with it.
-- Run 20260819002000 (orphan purge) first — Postgres validates this constraint
-- on ADD and a single orphan aborts it.
--
-- ON DELETE CASCADE, not SET NULL: raw_url is the personal data, so keeping the
-- row and blanking the owner would retain the very thing that must go. user_id
-- stays NULLABLE — pre-account/staff builds legitimately have no owner, and a
-- NULL FK column is unconstrained.
--
-- guard:no-unsafe-migrations:disable-file
--
-- Justification (2026-08-18): CONVENTIONS.md §4 and the guard's Check 2 require
-- the ADD ... NOT VALID / VALIDATE CONSTRAINT split, and that form is IMPOSSIBLE
-- here. link_observations is PARTITION BY RANGE (observed_at), and PostgreSQL
-- rejects a NOT VALID foreign key on a partitioned parent before 18 --
-- "cannot add NOT VALID foreign key on partitioned table" -- verified
-- empirically against dev (PostgreSQL 17.6) on a throwaway partitioned table
-- 2026-08-18. The reason to accept the plain form anyway: the table holds 7
-- rows after the purge, so the validating scan under ACCESS EXCLUSIVE is
-- sub-millisecond. The FK must go on the PARENT, not per-partition -- that is
-- what propagates it to the three existing partitions and to every partition
-- created later.
--
-- Rollback:
--   ALTER TABLE routing.link_observations
--       DROP CONSTRAINT IF EXISTS link_observations_user_id_fkey;

BEGIN;

-- The FK takes SHARE ROW EXCLUSIVE on core.users (a hot table) for the catalog
-- write. Fail fast rather than queue behind live traffic.
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE routing.link_observations
    DROP CONSTRAINT IF EXISTS link_observations_user_id_fkey;

ALTER TABLE routing.link_observations
    ADD CONSTRAINT link_observations_user_id_fkey
    FOREIGN KEY (user_id) REFERENCES core.users (id) ON DELETE CASCADE;

COMMIT;
