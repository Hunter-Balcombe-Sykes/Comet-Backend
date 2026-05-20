-- 202605190100000_drop_partna_url_not_null_for_bootstrap_chicken_egg.sql
--
-- Drops the NOT NULL constraint that 20260508300000_url_columns_not_null.sql
-- placed on core.professionals.partna_url + brand.brand_partner_links.site_url.
--
-- Why
-- ---
-- partna_url is trigger-managed: sites_url_sync_aiu (defined in
-- 20260508100000_url_columns_and_triggers.sql) fires AFTER INSERT or UPDATE
-- of subdomain ON site.sites, and inside that trigger it UPDATEs the matching
-- core.professionals row's partna_url.
--
-- But BootstrapController inserts the professional FIRST and creates the site
-- AFTER. During the professional INSERT, partna_url is necessarily NULL — the
-- site row doesn't exist yet, so no trigger has fired. With NOT NULL in place,
-- every fresh signup 500s with:
--   SQLSTATE[23502]: null value in column "partna_url"
--
-- Same chicken-and-egg for brand_partner_links.site_url: it's filled by the
-- partner_link_url_init_bi BEFORE INSERT trigger, but only when both the
-- brand's partna_url AND the affiliate handle are non-null. During a brand
-- account's own bootstrap (when their own partna_url is still NULL) any
-- partner-link insert hits the NOT NULL too.
--
-- Reverting to nullable restores the original design: the columns get
-- populated milliseconds later when the site row / partner link is created.
-- Reads must continue to handle NULL gracefully (resources already coalesce).

BEGIN;

ALTER TABLE core.professionals
    ALTER COLUMN partna_url DROP NOT NULL;

ALTER TABLE brand.brand_partner_links
    ALTER COLUMN site_url DROP NOT NULL;

COMMIT;
