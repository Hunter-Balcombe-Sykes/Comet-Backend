-- Reset brand_profiles.industries before the controlled-enum validation ships.
-- Pre-beta: no real customers. Existing values are free-form strings that will
-- fail the new enum check; clearing them forces brands to re-pick via the
-- dashboard multi-select.
--
-- Idempotent: re-running sets already-empty arrays to the same empty value.
-- setup_complete is cleared so a brand cannot remain "setup complete" while
-- holding zero valid industries.

UPDATE brand.brand_profiles
   SET industries = '[]'::jsonb,
       setup_complete = false
 WHERE jsonb_array_length(industries) > 0;
