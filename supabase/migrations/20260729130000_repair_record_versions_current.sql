-- Pre-flight for 20260729130001: the new UNIQUE partial index on
-- (stream_id, key) WHERE is_current will fail its build if any key
-- currently carries more than one is_current=true row — exactly what
-- DINT-16's revert bug (Lander::land() judging "changed" by INSERT row
-- count instead of by whether the CURRENT version moved) can leave behind.
--
-- record_state.current_version_id is the correct source of truth here (Unit
-- 2 plan Step 1, correction #1): it is written unconditionally on every
-- landing, independent of the buggy `changed` counter, so it never desynced
-- even while is_current did. For every (stream_id, key), set is_current to
-- true only on the row that record_state names, false on every other row
-- for that key.
--
-- Expected to match 0 rows today (the ingest fleet landed 2026-07-27 and
-- prod carries no customer data). Left in regardless: it is the only thing
-- standing between historical desync and a failed production migration.
--
-- ROLLBACK: NONE, and none wanted. Repairs is_current desync from DINT-16's
--           revert bug; the prior state was CORRUPT (two is_current=true
--           rows for one key) and restoring it would break the UNIQUE index
--           20260729130001 builds. No column records the pre-repair value.

UPDATE "ingest"."record_versions" rv
SET "is_current" = (rv."id" = rs."current_version_id")
FROM "ingest"."record_state" rs
WHERE rs."stream_id" = rv."stream_id"
  AND rs."key" = rv."key"
  AND rv."is_current" IS DISTINCT FROM (rv."id" = rs."current_version_id");
