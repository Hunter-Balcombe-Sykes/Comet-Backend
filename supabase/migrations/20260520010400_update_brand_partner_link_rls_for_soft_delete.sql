-- Plan §28.16 Migration C / audit SCHEMA-2.
--
-- The default Eloquent scope on a SoftDeletes-trait model filters deleted_at
-- IS NULL at the ORM layer, but the RLS predicates that mention
-- brand.brand_partner_links don't — so an authenticated user could query the
-- table directly via Supabase REST and see tombstoned rows. Defence-in-depth
-- requires the predicates themselves to filter.
--
-- Three policies updated (all non-staff branches gain `AND deleted_at IS NULL`
-- predicates; staff branches are unchanged):
--   - partner_links_party_select       — affiliate-side + brand-side equality
--   - brand_profiles_affiliate_select  — EXISTS subquery filter
--   - store_settings_affiliate_select  — EXISTS subquery filter
--
-- The three changes ship in this single migration so there is no window
-- between "soft-deletes exist" (column added in Migration A) and "RLS hides
-- them" — readers atomically transition.
--
-- To revert: drop+recreate the three policies without the deleted_at filters
-- (see prior definitions in 20260420200000_add_rls_to_remaining_tables.sql).

BEGIN;

-- ─── partner_links_party_select ─────────────────────────────────────────────
DROP POLICY IF EXISTS partner_links_party_select ON brand.brand_partner_links;

CREATE POLICY partner_links_party_select ON brand.brand_partner_links FOR SELECT TO authenticated
    USING (
        (
            affiliate_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
            AND deleted_at IS NULL
        )
        OR (
            brand_professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
            AND deleted_at IS NULL
        )
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

-- ─── brand_profiles_affiliate_select ────────────────────────────────────────
DROP POLICY IF EXISTS brand_profiles_affiliate_select ON brand.brand_profiles;

CREATE POLICY brand_profiles_affiliate_select ON brand.brand_profiles FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM brand.brand_partner_links l
        JOIN core.professionals p ON p.id = l.affiliate_professional_id
        WHERE l.brand_professional_id = brand_profiles.professional_id
          AND l.deleted_at IS NULL
          AND p.auth_user_id = auth.uid()
          AND p.deleted_at IS NULL
    ));

-- ─── store_settings_affiliate_select ────────────────────────────────────────
DROP POLICY IF EXISTS store_settings_affiliate_select ON brand.brand_store_settings;

CREATE POLICY store_settings_affiliate_select ON brand.brand_store_settings FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM brand.brand_partner_links l
        JOIN core.professionals p ON p.id = l.affiliate_professional_id
        WHERE l.brand_professional_id = professional_id
          AND l.deleted_at IS NULL
          AND p.auth_user_id = auth.uid()
          AND p.deleted_at IS NULL
    ));

COMMIT;
