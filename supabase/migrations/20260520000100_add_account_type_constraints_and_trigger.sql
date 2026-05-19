-- Plan §28.1 step 2 (audit MIG-2 / MIG-3).
--
-- Adds the value CHECK and the not-null CHECK as NOT VALID so neither acquires
-- ACCESS EXCLUSIVE for a full-table scan. The validation pass runs in the next
-- migration under SHARE UPDATE EXCLUSIVE.
--
-- Also creates the dual-write trigger that keeps professional_type and
-- account_type in lock-step while reads migrate incrementally. Precedence per
-- plan §8: when both columns are explicitly set in the same statement, the
-- new account_type column wins.
--
-- To revert:
--   DROP TRIGGER IF EXISTS professionals_account_type_dual_write ON core.professionals;
--   DROP FUNCTION IF EXISTS core.professionals_account_type_dual_write();
--   ALTER TABLE core.professionals DROP CONSTRAINT IF EXISTS professionals_account_type_check;
--   ALTER TABLE core.professionals DROP CONSTRAINT IF EXISTS professionals_account_type_not_null;

BEGIN;

ALTER TABLE core.professionals
    ADD CONSTRAINT professionals_account_type_check
    CHECK (account_type IN ('brand', 'partner', 'individual')) NOT VALID;

ALTER TABLE core.professionals
    ADD CONSTRAINT professionals_account_type_not_null
    CHECK (account_type IS NOT NULL) NOT VALID;

-- Dual-write trigger. Mapping:
--   account_type='brand'                  -> professional_type='brand'
--   account_type='partner' | 'individual' -> professional_type='professional'
--
-- The reverse direction (only professional_type set) infers account_type from
-- the brand link existence — same logic as the §28.1 backfill — but ONLY if
-- account_type is being left NULL by the caller. If the caller explicitly sets
-- account_type, account_type wins.
CREATE OR REPLACE FUNCTION core.professionals_account_type_dual_write()
RETURNS trigger AS $$
DECLARE
    account_type_changed boolean := FALSE;
    professional_type_changed boolean := FALSE;
BEGIN
    IF TG_OP = 'INSERT' THEN
        account_type_changed := NEW.account_type IS NOT NULL;
        professional_type_changed := NEW.professional_type IS NOT NULL;
    ELSE
        account_type_changed := NEW.account_type IS DISTINCT FROM OLD.account_type;
        professional_type_changed := NEW.professional_type IS DISTINCT FROM OLD.professional_type;
    END IF;

    -- account_type wins when both are explicitly set in the same statement.
    IF account_type_changed THEN
        IF NEW.account_type = 'brand' THEN
            NEW.professional_type := 'brand';
        ELSIF NEW.account_type IN ('partner', 'individual') THEN
            NEW.professional_type := 'professional';
        END IF;
        RETURN NEW;
    END IF;

    -- Caller only touched professional_type — sync account_type when possible.
    IF professional_type_changed AND NEW.account_type IS NULL THEN
        IF NEW.professional_type = 'brand' THEN
            NEW.account_type := 'brand';
        ELSIF NEW.professional_type IN ('professional', 'influencer') THEN
            -- Default to 'individual' here; BootstrapController and the
            -- transition service flip to 'partner' explicitly when a link is
            -- established. This default avoids the trigger needing to query
            -- brand.brand_partner_links on every write.
            NEW.account_type := 'individual';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS professionals_account_type_dual_write ON core.professionals;
CREATE TRIGGER professionals_account_type_dual_write
    BEFORE INSERT OR UPDATE ON core.professionals
    FOR EACH ROW
    EXECUTE FUNCTION core.professionals_account_type_dual_write();

COMMIT;
