-- 20260508200000_backfill_user_urls.sql
-- Patches two trigger functions from migration 20260508100000 that were applied
-- with bugs (alias collision + missing NULL guard), then backfills partna_url for
-- every professional with a linked site row, and site_url for every
-- existing brand_partner_links row.

BEGIN;

-- Patch 1: Fix trg_recompute_affiliate_path — rename 'brand' alias to 'brand_pro'
-- to avoid shadowing the 'brand' schema name.
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

-- Patch 2: Fix trg_recompute_partna_url — add NULL guard on the cascade so that
-- a NULL v_url (brand has no site row yet) does not wipe existing affiliate URLs.
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

-- Backfill professional partna_url. The compute function returns NULL for
-- professionals without a site row (e.g., newly-created accounts pre-site).
UPDATE core.professionals p
   SET partna_url = site.compute_professional_url(p.id)
 WHERE p.partna_url IS NULL;

-- Backfill brand_partner_links.site_url
UPDATE brand.brand_partner_links bpl
   SET site_url = brand_pro.partna_url || '/' || aff.handle
  FROM core.professionals brand_pro, core.professionals aff
 WHERE brand_pro.id = bpl.brand_professional_id
   AND aff.id       = bpl.affiliate_professional_id
   AND bpl.site_url IS NULL
   AND brand_pro.partna_url IS NOT NULL;

COMMIT;
