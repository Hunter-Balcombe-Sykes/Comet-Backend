-- =====================================================================
-- Derived-default design kit vars — focus border + button colors
-- =====================================================================
-- Adds 7 columns to back the first batch of derived-default vars in
-- @partnaau/design-system. All NULLABLE, no DB-level DEFAULT — these
-- vars have no entry in design-kit/defaults.ts because their default
-- resolves at render time from another var (see CLAUDE.md "Derived
-- defaults via var-referencing").
--
-- Storage convention (unchanged): flat snake_case column names; the
-- API maps to nested camelCase via groupKitColumns in
-- IndividualProfilePayloadBuilder.
--
-- Linkage (vars.css):
--   border_focus_color   ← var(--dk-color-accent)
--   button_primary_bg    ← var(--dk-color-accent)
--   button_primary_text  ← var(--dk-color-accent-contrast)
--   button_secondary_bg  ← var(--dk-color-text)
--   button_secondary_text← var(--dk-color-bg)
--   button_general_bg    ← var(--dk-color-bg)
--   button_general_text  ← var(--dk-color-text)
--
-- Adds a new `button` group prefix, which the payload builder's group
-- map picks up as `buttons` in the API response (camelCase plural).
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  -- Borders (derived)
  ADD COLUMN IF NOT EXISTS border_focus_color TEXT NULL,

  -- Buttons (new group, all derived)
  ADD COLUMN IF NOT EXISTS button_primary_bg TEXT NULL,
  ADD COLUMN IF NOT EXISTS button_primary_text TEXT NULL,
  ADD COLUMN IF NOT EXISTS button_secondary_bg TEXT NULL,
  ADD COLUMN IF NOT EXISTS button_secondary_text TEXT NULL,
  ADD COLUMN IF NOT EXISTS button_general_bg TEXT NULL,
  ADD COLUMN IF NOT EXISTS button_general_text TEXT NULL;

COMMIT;
