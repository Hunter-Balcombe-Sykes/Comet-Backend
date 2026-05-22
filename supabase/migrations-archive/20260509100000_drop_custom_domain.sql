-- 20260509100000_drop_custom_domain.sql
-- Removes custom-domain capability from brand_store_settings.
-- The trigger that reacted to custom_domain changes is dropped first; the URL
-- composition function is simplified to use only site.sites.subdomain.

BEGIN;

-- 1. Drop the trigger that fires on custom_domain changes.
DROP TRIGGER IF EXISTS store_settings_url_sync_aiu ON brand.brand_store_settings;
DROP FUNCTION IF EXISTS brand.trg_store_settings_url_sync();

-- 2. Simplify compute_professional_url — remove the custom_domain branch.
CREATE OR REPLACE FUNCTION site.compute_professional_url(p_professional_id uuid)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_subdomain text;
BEGIN
    SELECT s.subdomain INTO v_subdomain
      FROM site.sites s
     WHERE s.professional_id = p_professional_id;

    IF v_subdomain IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN 'https://' || v_subdomain || '.partna.au';
END;
$$;

-- 3. Drop the custom-domain columns.
ALTER TABLE brand.brand_store_settings
    DROP COLUMN IF EXISTS custom_domain,
    DROP COLUMN IF EXISTS custom_domain_verified_at,
    DROP COLUMN IF EXISTS custom_domain_tls_provisioned_at,
    DROP COLUMN IF EXISTS domain_mode,
    DROP COLUMN IF EXISTS domain_wizard_complete,
    DROP COLUMN IF EXISTS domain_txt_confirmed;

COMMIT;
