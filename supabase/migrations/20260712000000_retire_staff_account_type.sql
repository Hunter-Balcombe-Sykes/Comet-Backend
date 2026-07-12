-- 20260712000000_retire_staff_account_type.sql
--
-- Option A: retire the 'staff' account type. Internal staff identity + powers
-- live solely in core.partna_staff (role support/admin), gated by the `staff`
-- middleware and the staff Policies. account_type no longer encodes staff-ness.
--
-- The 3 existing account_type='staff' rows (tobias, joshhunter, staff-test) each
-- have a matching core.partna_staff row. Convert them to 'partna': they remain a
-- normal Partna user (keeping handle + any site) AND remain staff via
-- partna_staff. Their partna_staff rows are intentionally left untouched.
--
-- Reverses 20260711000000_staff_account_type.sql. Same DROP → ADD NOT VALID →
-- VALIDATE dance (CONVENTIONS §2); after the UPDATE no 'staff' rows remain so
-- VALIDATE is a clean pass.

-- 1. Demote the internal-staff user rows back to the standard account type.
UPDATE core.users SET account_type = 'partna' WHERE account_type = 'staff';

-- 2. Re-narrow the CHECK to the two user-selectable types.
ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;

ALTER TABLE core.users
    ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business')) NOT VALID;

ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;
