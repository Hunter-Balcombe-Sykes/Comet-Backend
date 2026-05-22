BEGIN;

-- Backfill any pre-enum legacy values that don't fit the new set.
-- 'cart_attribute' was a short-lived label used before the current enum was formalised;
-- brand_default is the closest semantic equivalent.
UPDATE commerce.orders
    SET rate_source = 'brand_default'
    WHERE rate_source NOT IN
        ('product_metafield','metafield_override','brand_default','platform_default','manual','pending');

-- Today rate_source has a DEFAULT but no CHECK. Lock the enum so 'pending'
-- (introduced for out-of-bounds metafields) is the only new admissible value
-- and typos can't drift the field.
ALTER TABLE commerce.orders
    DROP CONSTRAINT IF EXISTS chk_orders_rate_source;

ALTER TABLE commerce.orders
    ADD CONSTRAINT chk_orders_rate_source
    CHECK (rate_source IN
        ('product_metafield','metafield_override','brand_default','platform_default','manual','pending'));

COMMIT;

-- DOWN:
-- BEGIN;
-- ALTER TABLE commerce.orders DROP CONSTRAINT IF EXISTS chk_orders_rate_source;
-- COMMIT;
