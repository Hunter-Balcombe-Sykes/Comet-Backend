-- 20260508300000_url_columns_not_null.sql
-- Now that backfill has populated all rows that have a site, enforce NOT NULL.
-- Note: professionals without a site row (pre-site accounts) may still have
-- NULL partna_url — if the constraint fails, check for professionals with no
-- site.sites row.

BEGIN;

ALTER TABLE core.professionals
    ALTER COLUMN partna_url SET NOT NULL;

ALTER TABLE brand.brand_partner_links
    ALTER COLUMN site_url SET NOT NULL;

COMMIT;
