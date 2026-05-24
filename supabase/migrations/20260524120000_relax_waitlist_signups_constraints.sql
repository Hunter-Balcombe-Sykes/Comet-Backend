-- Relax core.waitlist_signups to match the email-only signup contract.
-- The V2 baseline (20260526000000_baseline_standalone_user.sql) enforces
-- NOT NULL on name/phone/applicant_type/industry, but both endpoints that
-- write to this table (PublicWaitlistController and the BootstrapController
-- individual-waitlist divert) already pass NULLs for those columns. The
-- baseline schema reflects an earlier multi-field form that no longer
-- matches the frontend contract.
--
-- This migration:
--   1. Drops NOT NULL on name, phone, applicant_type, industry (metadata-only
--      ALTER, safe even on populated tables).
--   2. Replaces 4 CHECK constraints (applicant_type, industry, and the two
--      *_other_required pair-checks) using the NOT VALID + VALIDATE split
--      pattern from supabase/migrations/CONVENTIONS.md §2 — keeps the lock
--      window minimal even though the table is small today.

-- Step A — metadata-only changes (DROP NOT NULL) + DROP old CHECKs + ADD new
-- CHECKs as NOT VALID. NOT VALID skips the existing-row scan, releasing the
-- ACCESS EXCLUSIVE lock immediately after the catalog write.
BEGIN;

ALTER TABLE core.waitlist_signups
    ALTER COLUMN name DROP NOT NULL,
    ALTER COLUMN phone DROP NOT NULL,
    ALTER COLUMN applicant_type DROP NOT NULL,
    ALTER COLUMN industry DROP NOT NULL;

ALTER TABLE core.waitlist_signups
    DROP CONSTRAINT IF EXISTS waitlist_signups_type_check;

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_type_check CHECK (
        applicant_type IS NULL
        OR applicant_type IN ('influencer', 'professional', 'other', 'individual')
    ) NOT VALID;

ALTER TABLE core.waitlist_signups
    DROP CONSTRAINT IF EXISTS waitlist_signups_industry_check;

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_industry_check CHECK (
        industry IS NULL
        OR industry IN (
            'mens_grooming', 'womens_haircare', 'beauty_products',
            'vitamins_and_supplements', 'services_and_software', 'other'
        )
    ) NOT VALID;

ALTER TABLE core.waitlist_signups
    DROP CONSTRAINT IF EXISTS waitlist_signups_type_other_required;

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_type_other_required CHECK (
        applicant_type IS NULL
        OR (applicant_type = 'other' AND applicant_type_other IS NOT NULL AND btrim(applicant_type_other) <> '')
        OR (applicant_type <> 'other' AND applicant_type_other IS NULL)
    ) NOT VALID;

ALTER TABLE core.waitlist_signups
    DROP CONSTRAINT IF EXISTS waitlist_signups_industry_other_required;

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_industry_other_required CHECK (
        industry IS NULL
        OR (industry = 'other' AND industry_other IS NOT NULL AND btrim(industry_other) <> '')
        OR (industry <> 'other' AND industry_other IS NULL)
    ) NOT VALID;

COMMIT;

-- Step B — VALIDATE in a separate transaction. Acquires SHARE UPDATE EXCLUSIVE
-- (not ACCESS EXCLUSIVE), allowing concurrent INSERT/UPDATE/DELETE during the
-- existing-row scan.
BEGIN;

ALTER TABLE core.waitlist_signups VALIDATE CONSTRAINT waitlist_signups_type_check;
ALTER TABLE core.waitlist_signups VALIDATE CONSTRAINT waitlist_signups_industry_check;
ALTER TABLE core.waitlist_signups VALIDATE CONSTRAINT waitlist_signups_type_other_required;
ALTER TABLE core.waitlist_signups VALIDATE CONSTRAINT waitlist_signups_industry_other_required;

COMMIT;
