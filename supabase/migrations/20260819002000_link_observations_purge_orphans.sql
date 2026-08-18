-- 20260819002000_link_observations_purge_orphans.sql
--
-- Overnight 2026-08-18 R3, step 1 of 2. routing.link_observations.user_id
-- carries no foreign key, so deleting a user leaves its observations behind —
-- still tagged with the dead user's id and still holding raw_url, a PII
-- carrier (see LinkObserver::record()'s #PRIV-5 note: minimiseUrl() strips
-- secrets, not identity). Its three routing.* neighbours (source_intents,
-- item_tombstones, import_runs) all cascade; this one never did. Measured on
-- dev 2026-08-18: 49 rows, 42 of them orphaned.
--
-- This file clears the existing orphans so 20260819002100 can add the FK
-- (Postgres validates on ADD; a single surviving orphan aborts it). Deleting
-- is correct rather than nulling the link: raw_url IS the personal data, so
-- keeping the row and dropping the user_id would retain exactly what has to
-- go. Rows with user_id IS NULL are pre-account/staff builds and are left
-- untouched by design.
--
-- Not wrapped in BEGIN/COMMIT: CONVENTIONS.md §5 keeps DML out of a migration
-- transaction. The table has no reject-mutation trigger (verified on dev,
-- pg_trigger: 0 user triggers), so a plain DELETE is permitted.
--
-- Rollback: none possible, and none wanted — these rows are the privacy
-- problem. Re-running is harmless (idempotent: matches nothing on a clean DB).

DELETE FROM routing.link_observations o
WHERE o.user_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM core.users u WHERE u.id = o.user_id);
