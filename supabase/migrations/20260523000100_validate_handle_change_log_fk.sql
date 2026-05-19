-- Plan §28.17 audit DATA-2 — part 2 of 2 (validate the FK).
--
-- VALIDATE CONSTRAINT lives in its own transaction so it acquires only
-- SHARE UPDATE EXCLUSIVE (concurrent reads + writes still proceed), rather
-- than extending the outer txn's ACCESS EXCLUSIVE window. Per CONVENTIONS §4.
--
-- To revert: no-op (the constraint stays valid). To roll back the FK swap,
-- see 20260523000000's "To revert" header.

BEGIN;

ALTER TABLE core.handle_change_log
    VALIDATE CONSTRAINT handle_change_log_professional_id_fkey;

COMMIT;
