-- Step 1: drop old constraint and add new one as NOT VALID (lock-light, instant).
BEGIN;

ALTER TABLE core.feature_flag_overrides
    DROP CONSTRAINT IF EXISTS feature_flag_overrides_scope_set;

ALTER TABLE core.feature_flag_overrides
    ADD CONSTRAINT feature_flag_overrides_scope_xor CHECK (
        (professional_id IS NOT NULL) <> (brand_id IS NOT NULL)
    ) NOT VALID;

COMMIT;

-- Step 2: validate in a separate transaction (sequential scan, weaker lock).
ALTER TABLE core.feature_flag_overrides
    VALIDATE CONSTRAINT feature_flag_overrides_scope_xor;
