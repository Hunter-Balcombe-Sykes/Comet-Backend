-- 20260508100000_url_columns_and_triggers.sql
-- Adds partna_url and site_url columns (nullable initially),
-- creates the professional_handle_aliases table, defines the URL composition
-- function, and installs five triggers that keep URL columns in sync.

BEGIN;

-- 1. New columns (nullable; backfill in migration 2 will populate, NOT NULL added in migration 3)
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS partna_url text NULL;

ALTER TABLE brand.brand_partner_links
    ADD COLUMN IF NOT EXISTS site_url text NULL;

-- 2. Lookup indexes
CREATE INDEX IF NOT EXISTS professionals_partna_url_idx
    ON core.professionals (partna_url) WHERE partna_url IS NOT NULL;

CREATE INDEX IF NOT EXISTS brand_partner_links_site_url_idx
    ON brand.brand_partner_links (site_url) WHERE site_url IS NOT NULL;

-- 3. Professional handle alias table — mirrors site.site_subdomain_aliases
CREATE TABLE IF NOT EXISTS site.professional_handle_aliases (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    handle varchar(63) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS professional_handle_aliases_handle_lc_uq
    ON site.professional_handle_aliases (LOWER(handle));
CREATE INDEX IF NOT EXISTS professional_handle_aliases_professional_idx
    ON site.professional_handle_aliases (professional_id);

ALTER TABLE site.professional_handle_aliases ENABLE ROW LEVEL SECURITY;

-- 4. URL composition function (single source of truth for URL construction)
CREATE OR REPLACE FUNCTION site.compute_professional_url(p_professional_id uuid)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_subdomain text;
    v_custom_domain text;
    v_custom_verified timestamptz;
BEGIN
    SELECT bss.custom_domain, bss.custom_domain_verified_at
      INTO v_custom_domain, v_custom_verified
      FROM brand.brand_store_settings bss
     WHERE bss.professional_id = p_professional_id;

    IF v_custom_domain IS NOT NULL AND v_custom_verified IS NOT NULL THEN
        RETURN 'https://' || v_custom_domain;
    END IF;

    SELECT s.subdomain INTO v_subdomain
      FROM site.sites s
     WHERE s.professional_id = p_professional_id;

    IF v_subdomain IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN 'https://' || v_subdomain || '.partna.au';
END;
$$;

-- 5. Trigger function: recompute partna_url + cascade to brand_partner_links
CREATE OR REPLACE FUNCTION site.trg_recompute_partna_url(p_professional_id uuid)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    v_url text;
BEGIN
    v_url := site.compute_professional_url(p_professional_id);

    UPDATE core.professionals
       SET partna_url = v_url
     WHERE id = p_professional_id;

    -- Cascade: if this professional is a brand, every connected affiliate's URL
    -- (brand subdomain + affiliate handle) needs to recompute.
    -- Only cascade when v_url is not null; avoids wiping affiliate URLs during
    -- transitional states (e.g., brand's site row doesn't exist yet).
    IF v_url IS NOT NULL THEN
        UPDATE brand.brand_partner_links bpl
           SET site_url = v_url || '/' || p.handle
          FROM core.professionals p
         WHERE bpl.brand_professional_id = p_professional_id
           AND bpl.affiliate_professional_id = p.id;
    END IF;
END;
$$;

-- 6. Trigger function: when an affiliate's handle changes, every brand_partner_links
-- row where this professional is the AFFILIATE has its URL path-segment updated.
CREATE OR REPLACE FUNCTION site.trg_recompute_affiliate_path(p_affiliate_id uuid, p_new_handle text)
RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    UPDATE brand.brand_partner_links bpl
       SET site_url = brand_pro.partna_url || '/' || p_new_handle
      FROM core.professionals brand_pro
     WHERE bpl.affiliate_professional_id = p_affiliate_id
       AND bpl.brand_professional_id = brand_pro.id;
END;
$$;

-- 7. Trigger 1: site.sites INSERT or UPDATE OF subdomain
CREATE OR REPLACE FUNCTION site.trg_sites_url_sync()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM site.trg_recompute_partna_url(NEW.professional_id);
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS sites_url_sync_aiu ON site.sites;
CREATE TRIGGER sites_url_sync_aiu
    AFTER INSERT OR UPDATE OF subdomain ON site.sites
    FOR EACH ROW EXECUTE FUNCTION site.trg_sites_url_sync();

-- 8. Trigger 2: brand.brand_store_settings INSERT or UPDATE OF custom_domain*
CREATE OR REPLACE FUNCTION brand.trg_store_settings_url_sync()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM site.trg_recompute_partna_url(NEW.professional_id);
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS store_settings_url_sync_aiu ON brand.brand_store_settings;
CREATE TRIGGER store_settings_url_sync_aiu
    AFTER INSERT OR UPDATE OF custom_domain, custom_domain_verified_at ON brand.brand_store_settings
    FOR EACH ROW EXECUTE FUNCTION brand.trg_store_settings_url_sync();

-- 9. Trigger 3 (AFTER): core.professionals UPDATE OF handle
-- Inserts old handle into aliases + recomputes affiliate-side URL paths.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_change()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO site.professional_handle_aliases (professional_id, handle)
    VALUES (NEW.id, OLD.handle)
    ON CONFLICT DO NOTHING;

    PERFORM site.trg_recompute_affiliate_path(NEW.id, NEW.handle);

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS professional_handle_change_au ON core.professionals;
CREATE TRIGGER professional_handle_change_au
    AFTER UPDATE OF handle ON core.professionals
    FOR EACH ROW
    WHEN (OLD.handle IS DISTINCT FROM NEW.handle)
    EXECUTE FUNCTION core.trg_professional_handle_change();

-- 10. Trigger 4: brand.brand_partner_links BEFORE INSERT — initial URL computation
CREATE OR REPLACE FUNCTION brand.trg_partner_link_url_init()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_brand_url text;
    v_affiliate_handle text;
BEGIN
    SELECT partna_url INTO v_brand_url
      FROM core.professionals WHERE id = NEW.brand_professional_id;

    SELECT handle INTO v_affiliate_handle
      FROM core.professionals WHERE id = NEW.affiliate_professional_id;

    IF v_brand_url IS NOT NULL AND v_affiliate_handle IS NOT NULL THEN
        NEW.site_url := v_brand_url || '/' || v_affiliate_handle;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS partner_link_url_init_bi ON brand.brand_partner_links;
CREATE TRIGGER partner_link_url_init_bi
    BEFORE INSERT ON brand.brand_partner_links
    FOR EACH ROW EXECUTE FUNCTION brand.trg_partner_link_url_init();

-- 11. Trigger 5 (BEFORE): core.professionals BEFORE UPDATE OF handle
-- Constraint check: prevent renaming into a handle that's currently aliased to another professional.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_alias_check()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_blocking_pro uuid;
BEGIN
    IF NEW.handle IS NOT DISTINCT FROM OLD.handle THEN
        RETURN NEW;
    END IF;

    SELECT professional_id INTO v_blocking_pro
      FROM site.professional_handle_aliases
     WHERE LOWER(handle) = LOWER(NEW.handle)
       AND professional_id <> NEW.id
     LIMIT 1;

    IF v_blocking_pro IS NOT NULL THEN
        RAISE EXCEPTION 'Handle % is reserved as a redirect for another professional', NEW.handle
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS professional_handle_alias_check_bu ON core.professionals;
CREATE TRIGGER professional_handle_alias_check_bu
    BEFORE UPDATE OF handle ON core.professionals
    FOR EACH ROW
    EXECUTE FUNCTION core.trg_professional_handle_alias_check();

COMMIT;
