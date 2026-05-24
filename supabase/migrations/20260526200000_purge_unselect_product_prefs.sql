-- B11/P2-31: Remove orphaned 'unselect_product' confirmation preference rows.
-- The product-selection feature was removed in the 2026-05-22 standalone strip.
-- Any rows with action_key = 'unselect_product' are dead data and are never read.

DELETE FROM core.professional_confirmation_preferences
WHERE action_key = 'unselect_product';
