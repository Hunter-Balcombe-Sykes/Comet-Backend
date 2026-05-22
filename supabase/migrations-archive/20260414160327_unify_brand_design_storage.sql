-- Unify brand design storage at site.settings.design (new shape).
--
-- Why: design values were scattered across three places —
--   1. site.settings.design.*                               (user-editable)
--   2. core.professional_integrations.provider_metadata
--        .theme_tokens                                      (Shopify-imported)
--   3. core.professional_integrations.provider_metadata
--        .sitepage_overrides                                (user overrides of #2)
-- Plus a `sidest.theme_tokens` Shopify shop metafield mirror of #2.
--
-- This migration:
--   • Seeds the new nested shape on every site (additive, non-destructive).
--   • Carries colour values from provider_metadata.theme_tokens into the new
--     site.settings.design.colors.* keys so Hydrogen (which we're about to
--     repoint to the new location) does not visually regress.
--   • Strips the deprecated provider_metadata subtrees.
--
-- The legacy user-editable keys (white_color, dark_color, border_radius CSS
-- string, general_spacing_padding, typography.*, media.brand_logo_url, etc.)
-- are intentionally LEFT in place — the existing /account/design UI still
-- reads them. A follow-up migration will drop them once the UI is rebuilt
-- against the new shape.
--
-- New shape under site.settings.design:
--   colors.{background, text, accent, border}   hex strings (nullable)
--   corner_radius                               enum: square | rounded | pill
--   border_thickness                            enum: hairline | standard | bold
--   section_spacing                             enum: tight | default | spacious
--   logo.{full_url, square_url}                 URLs to our own storage
--   slogan                                      short string
--
-- Note: every UPDATE is guarded by `jsonb_typeof(settings) = 'object'`. An
-- earlier revision of this migration didn't include that guard and blew up on
-- rows where `settings` had somehow become a JSON array — the sibling
-- migration 20260414160416_fix_design_array_to_object repairs those rows.

BEGIN;

-- Step 1 — Seed the new shape on every site.
-- COALESCE lifts pre-existing user-editable colour values (accent_color,
-- border_color, white_color→background, dark_color→text) so brands that have
-- already customised the design page keep their choices.
--
-- Enum-valued fields (corner_radius, border_thickness, section_spacing) are
-- seeded as NULL intentionally — the new sync job / UI will fill them. The
-- legacy CSS-length keys remain untouched for the current UI.
UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design}',
    COALESCE(settings->'design', '{}'::jsonb) || jsonb_build_object(
        'colors', jsonb_build_object(
            'background', settings->'design'->>'white_color',
            'text',       settings->'design'->>'dark_color',
            'accent',     settings->'design'->>'accent_color',
            'border',     settings->'design'->>'border_color'
        ),
        'corner_radius',    NULL,
        'border_thickness', NULL,
        'section_spacing',  NULL,
        'logo', jsonb_build_object(
            'full_url',   settings->'design'->'media'->>'brand_logo_url',
            'square_url', NULL
        ),
        'slogan', NULL
    ),
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object';

-- Step 2 — For brands with a Shopify integration, overlay Shopify-imported
-- colour values on top of any NULL slots in site.settings.design.colors.
-- Only fills gaps; a user-set accent_color from step 1 wins over the theme's
-- primary_color pulled from the old theme_tokens.
--
-- Mapping: theme_tokens.primary_color → colors.accent (accent is our
-- single hero colour; Shopify's theme "primary" is semantically the same).
UPDATE site.sites s
SET settings = jsonb_set(
    s.settings,
    '{design,colors}',
    COALESCE(s.settings->'design'->'colors', '{}'::jsonb) || jsonb_build_object(
        'background', COALESCE(
            NULLIF(s.settings->'design'->'colors'->>'background', ''),
            pi.provider_metadata->'theme_tokens'->>'background_color'
        ),
        'text', COALESCE(
            NULLIF(s.settings->'design'->'colors'->>'text', ''),
            pi.provider_metadata->'theme_tokens'->>'text_color'
        ),
        'accent', COALESCE(
            NULLIF(s.settings->'design'->'colors'->>'accent', ''),
            pi.provider_metadata->'theme_tokens'->>'primary_color'
        ),
        'border', s.settings->'design'->'colors'->>'border'
    ),
    true
)
FROM core.professional_integrations pi
WHERE pi.professional_id = s.professional_id
  AND pi.provider = 'shopify'
  AND pi.provider_metadata ? 'theme_tokens'
  AND jsonb_typeof(s.settings) = 'object';

-- Step 3 — Strip deprecated subtrees from provider_metadata on Shopify rows.
-- After this, theme design lives exclusively in site.settings.design.
UPDATE core.professional_integrations
SET provider_metadata = provider_metadata
    - 'theme_tokens'
    - 'sitepage_overrides'
    - 'theme_tokens_synced_at'
    - 'primary_domain_url'
WHERE provider = 'shopify'
  AND (
      provider_metadata ? 'theme_tokens'
      OR provider_metadata ? 'sitepage_overrides'
      OR provider_metadata ? 'theme_tokens_synced_at'
      OR provider_metadata ? 'primary_domain_url'
  );

COMMIT;
