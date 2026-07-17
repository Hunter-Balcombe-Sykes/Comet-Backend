-- =====================================================================
-- Validate blocks_group_type_check (split out of 20260705120000, MIG-1)
-- =====================================================================
-- The constraint was added NOT VALID in 20260705120000 as part of a swap
-- (DROP CONSTRAINT + ADD CONSTRAINT ... NOT VALID) that itself needs
-- ACCESS EXCLUSIVE. VALIDATE CONSTRAINT only needs SHARE UPDATE EXCLUSIVE
-- (blocks other DDL, but not concurrent sitepage reads/writes), so it runs
-- in its own transaction rather than extending that file's ACCESS EXCLUSIVE
-- window for the row scan.
-- =====================================================================
BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.blocks VALIDATE CONSTRAINT blocks_group_type_check;

COMMIT;
