-- Seed site.settings.design.font_family on every brand site.
--
-- Why: we introduced a fixed-shortlist font picker (5 options). Existing
-- brands have no selection, so we seed 'helvetica_neue' as the Sidest default
-- and clear the legacy free-string typography keys that the picker replaces.
--
-- The backend treats a missing/empty font_family as the default too (see
-- BrandDesignController::DEFAULT_FONT_FAMILY), so this migration is belt-and-
-- braces — making the stored value explicit keeps the DB consistent with what
-- every API surface returns.
--
-- Note: guarded by `jsonb_typeof(settings) = 'object'` for the same reason as
-- the sibling design-unification migration — a small number of rows had
-- `settings` stored as a JSON array, which this guard skips and which
-- 20260414160416_fix_design_array_to_object repairs.

BEGIN;

-- Step 1 — Default font_family for every site that doesn't already have one.
-- Uses || so any existing value wins (no brand with a pre-existing choice gets
-- clobbered).
UPDATE site.sites
SET settings = jsonb_set(
    COALESCE(settings, '{}'::jsonb),
    '{design}',
    COALESCE(settings->'design', '{}'::jsonb)
        || jsonb_build_object('font_family', 'helvetica_neue'),
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND (
      settings->'design'->>'font_family' IS NULL
      OR settings->'design'->>'font_family' = ''
  );

-- Step 2 — Drop the legacy free-string typography keys now that font_family
-- replaces them. The backend has already flipped them to `prohibited` so no
-- new writes are landing; this clears the existing values.
UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design,typography}',
    COALESCE(settings->'design'->'typography', '{}'::jsonb)
        - 'heading_font'
        - 'body_font'
        - 'font_file_name'
        - 'font_file_path'
        - 'font_file_url',
    true
)
WHERE settings IS NOT NULL
  AND jsonb_typeof(settings) = 'object'
  AND settings->'design'->'typography' IS NOT NULL;

COMMIT;
