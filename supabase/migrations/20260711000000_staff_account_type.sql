-- 20260711000000_staff_account_type.sql
--
-- OV-A: adds the third account type 'staff' — internal Partna staff accounts.
-- Staff accounts have NO site and NO integrations; their powers are granular
-- capabilities derived in AccountCapabilities from the linked core.partna_staff
-- role (the core.users row carries account_type='staff'; the partna_staff row
-- keyed by the same auth_user_id carries the role). Signup validation keeps
-- rejecting 'staff' — these rows are created by staff tooling/tinker only.
--
-- Same DROP → ADD NOT VALID → VALIDATE dance as 20260612120000 (CONVENTIONS §2):
-- existing rows are all 'partna'/'business' so VALIDATE is a clean pass.

ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;

ALTER TABLE core.users
    ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business', 'staff')) NOT VALID;

ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;
