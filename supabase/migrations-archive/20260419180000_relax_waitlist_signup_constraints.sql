-- Relax core.waitlist_signups so an email-only signup is valid.
-- Context: the public coming-soon landing page captures only an email.
-- Previously the table required name/phone/applicant_type/industry plus a strict
-- per-type CHECK matrix. We keep email + email_lc + pilot_program_opt_in NOT NULL
-- (the latter has a default), and rewrite the CHECK constraints to allow a
-- partially-filled row while still enforcing consistency when fields ARE provided.

BEGIN;

-- Drop the existing CHECK constraints that assume all fields are populated.
ALTER TABLE core.waitlist_signups DROP CONSTRAINT IF EXISTS waitlist_signups_type_check;
ALTER TABLE core.waitlist_signups DROP CONSTRAINT IF EXISTS waitlist_signups_industry_check;
ALTER TABLE core.waitlist_signups DROP CONSTRAINT IF EXISTS waitlist_signups_type_other_required;
ALTER TABLE core.waitlist_signups DROP CONSTRAINT IF EXISTS waitlist_signups_industry_other_required;
ALTER TABLE core.waitlist_signups DROP CONSTRAINT IF EXISTS waitlist_signups_conditional_fields_check;

-- Make the previously-required descriptive fields nullable.
ALTER TABLE core.waitlist_signups ALTER COLUMN name DROP NOT NULL;
ALTER TABLE core.waitlist_signups ALTER COLUMN phone DROP NOT NULL;
ALTER TABLE core.waitlist_signups ALTER COLUMN applicant_type DROP NOT NULL;
ALTER TABLE core.waitlist_signups ALTER COLUMN industry DROP NOT NULL;

-- Re-add CHECK constraints that allow NULL but enforce the enum / shape when present.
ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_type_check
    CHECK (applicant_type IS NULL OR applicant_type IN ('influencer', 'professional', 'brand', 'other'));

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_industry_check
    CHECK (
        industry IS NULL
        OR industry IN ('mens_grooming', 'womens_haircare', 'beauty_products', 'vitamins_and_supplements', 'services_and_software', 'other')
    );

-- 'other' free-text fields: required only when the corresponding enum value is 'other',
-- and forbidden otherwise. NULL applicant_type / industry implies NULL on the *_other column.
ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_type_other_required
    CHECK (
        (applicant_type = 'other' AND applicant_type_other IS NOT NULL AND btrim(applicant_type_other) <> '')
        OR (applicant_type IS DISTINCT FROM 'other' AND applicant_type_other IS NULL)
    );

ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_industry_other_required
    CHECK (
        (industry = 'other' AND industry_other IS NOT NULL AND btrim(industry_other) <> '')
        OR (industry IS DISTINCT FROM 'other' AND industry_other IS NULL)
    );

-- Conditional matrix: still enforced when applicant_type is set; when NULL, all
-- conditional fields must be NULL (an email-only signup row).
ALTER TABLE core.waitlist_signups
    ADD CONSTRAINT waitlist_signups_conditional_fields_check
    CHECK (
        (applicant_type IS NULL
            AND number_of_team_members IS NULL
            AND number_of_affiliates_ambassadors IS NULL
            AND is_brand_partner_or_ambassador IS NULL
            AND currently_sells_products IS NULL)
        OR (applicant_type = 'brand'
            AND number_of_team_members IS NOT NULL
            AND number_of_affiliates_ambassadors IS NOT NULL
            AND is_brand_partner_or_ambassador IS NULL
            AND currently_sells_products IS NULL)
        OR (applicant_type IN ('influencer', 'professional')
            AND number_of_team_members IS NULL
            AND number_of_affiliates_ambassadors IS NULL
            AND is_brand_partner_or_ambassador IS NOT NULL
            AND currently_sells_products IS NOT NULL)
        OR (applicant_type = 'other'
            AND number_of_team_members IS NULL
            AND number_of_affiliates_ambassadors IS NULL
            AND is_brand_partner_or_ambassador IS NULL
            AND currently_sells_products IS NULL)
    );

COMMIT;
