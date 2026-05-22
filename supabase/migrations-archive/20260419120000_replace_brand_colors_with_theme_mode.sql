-- Replace per-brand background/text/border color pickers with a single
-- theme_mode enum ('light' | 'dark'), mirroring the existing
-- corner_radius / border_thickness / section_spacing bucket pattern.
--
-- Why: brand-picked colour triples produce fragile combinations and force
-- every theme to handle arbitrary hex values. A single mode lets each theme
-- own its concrete light/dark palette in its tokens.css.
--
-- Scope: settings.design.colors keeps `accent` (still brand-picked).
-- The bg/text/border slots — plus the legacy white_color/dark_color/border_color
-- aliases — are dropped. Existing brands default to 'light'.
--
-- Guarded by jsonb_typeof(settings) = 'object' for the same reason as the
-- sibling design migrations: a small number of legacy rows have settings
-- stored as a JSON array, which 20260414160416_fix_design_array_to_object.sql
-- repairs separately.

BEGIN;

-- Step 1 — Seed theme_mode = 'light' for every brand site without a value.
UPDATE site.sites
SET settings = jsonb_set(
    COALESCE(settings, '{}'::jsonb),
    '{design}',
    COALESCE(settings->'design', '{}'::jsonb)
        || jsonb_build_object('theme_mode', 'light'),
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND (
      settings->'design'->>'theme_mode' IS NULL
      OR settings->'design'->>'theme_mode' = ''
  );

-- Step 2 — Drop the now-unused colour slots from the unified colors object.
-- Accent is preserved.
UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design,colors}',
    COALESCE(settings->'design'->'colors', '{}'::jsonb)
        - 'background'
        - 'text'
        - 'border',
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND settings->'design'->'colors' IS NOT NULL;

-- Step 3 — Drop the legacy free-key colour aliases. The Form Request stops
-- accepting writes to these in the same change, so this clears anything that
-- was already on disk.
UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design}',
    (settings->'design')
        - 'white_color'
        - 'dark_color'
        - 'border_color'
        - 'background_color'
        - 'text_color',
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND settings->'design' IS NOT NULL;

COMMIT;
