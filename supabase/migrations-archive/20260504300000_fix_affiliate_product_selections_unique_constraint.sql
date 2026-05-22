-- #9-015: The UNIQUE on affiliate_product_selections was never updated when
-- brand_professional_id was added. Without brand_id in the key, the same
-- product could not be selected by the same affiliate for two different brands.

-- Step 1: Remove any duplicate rows that would violate the new 3-column key.
-- Pre-beta so this should be a no-op, but we guard against it explicitly.
DELETE FROM commerce.affiliate_product_selections
WHERE id NOT IN (
    SELECT DISTINCT ON (affiliate_professional_id, brand_professional_id, shopify_product_gid) id
    FROM commerce.affiliate_product_selections
    ORDER BY affiliate_professional_id, brand_professional_id, shopify_product_gid, created_at
);

-- Step 2: Drop the old 2-column unique constraint (name was auto-generated and
-- may be truncated, so we look it up from pg_constraint instead of hardcoding).
DO $$
DECLARE
    v_constraint text;
BEGIN
    SELECT conname INTO v_constraint
    FROM pg_constraint
    WHERE conrelid = 'commerce.affiliate_product_selections'::regclass
      AND contype = 'u'
      AND conkey = ARRAY[
            (SELECT attnum FROM pg_attribute
             WHERE attrelid = 'commerce.affiliate_product_selections'::regclass
               AND attname = 'affiliate_professional_id'),
            (SELECT attnum FROM pg_attribute
             WHERE attrelid = 'commerce.affiliate_product_selections'::regclass
               AND attname = 'shopify_product_gid')
          ]::smallint[];

    IF v_constraint IS NOT NULL THEN
        EXECUTE format('ALTER TABLE commerce.affiliate_product_selections DROP CONSTRAINT %I', v_constraint);
    END IF;
END;
$$;

-- Step 3: Add the corrected 3-column unique constraint that includes brand scope.
ALTER TABLE commerce.affiliate_product_selections
    ADD CONSTRAINT affiliate_product_selections_affiliate_brand_product_unique
    UNIQUE (affiliate_professional_id, brand_professional_id, shopify_product_gid);
