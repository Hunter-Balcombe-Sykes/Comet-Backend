-- Plan §28.1 step 3.
--
-- Validates both CHECK constraints from the previous migration under SHARE
-- UPDATE EXCLUSIVE (concurrent reads and writes still proceed), guards against
-- an incomplete backfill, then promotes the column to a true NOT NULL.
--
-- The SET NOT NULL is near-instant because Postgres skips the row scan when a
-- validated NOT NULL CHECK already exists.
--
-- To revert:
--   ALTER TABLE core.professionals ALTER COLUMN account_type DROP NOT NULL;
--   ALTER TABLE core.professionals ADD CONSTRAINT professionals_account_type_not_null CHECK (account_type IS NOT NULL) NOT VALID;

BEGIN;

ALTER TABLE core.professionals
    VALIDATE CONSTRAINT professionals_account_type_check;

ALTER TABLE core.professionals
    VALIDATE CONSTRAINT professionals_account_type_not_null;

-- Defensive guard: if any row is still NULL at this point, the backfill is
-- incomplete. Fail loudly rather than silently leaving the column nullable.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM core.professionals WHERE account_type IS NULL) THEN
        RAISE EXCEPTION 'account_type backfill incomplete: % rows still NULL',
            (SELECT count(*) FROM core.professionals WHERE account_type IS NULL);
    END IF;
END $$;

ALTER TABLE core.professionals
    ALTER COLUMN account_type SET NOT NULL;

-- The column-level NOT NULL now subsumes the explicit CHECK.
ALTER TABLE core.professionals
    DROP CONSTRAINT professionals_account_type_not_null;

COMMIT;
