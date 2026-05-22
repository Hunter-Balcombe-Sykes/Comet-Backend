-- 20260508700000_fix_trigger5_when_clause.sql
-- Adds WHEN clause to professional_handle_alias_check_bu to match Trigger 3.
-- Avoids unnecessary function invocations on handle UPDATEs where handle didn't change.

BEGIN;

DROP TRIGGER IF EXISTS professional_handle_alias_check_bu ON core.professionals;
CREATE TRIGGER professional_handle_alias_check_bu
    BEFORE UPDATE OF handle ON core.professionals
    FOR EACH ROW
    WHEN (OLD.handle IS DISTINCT FROM NEW.handle)
    EXECUTE FUNCTION core.trg_professional_handle_alias_check();

COMMIT;
