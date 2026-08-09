-- site.design_kits goes PRESET-ONLY: 57 value columns → 8.
-- (2026-08-09 design-kit go-live, brief §3.2 / §8.2; plan phase 6.2.)
--
-- Net −49, which is the figure the brief quotes. The clauses below are 52
-- DROPs and 3 ADDs: five of the eight survivors already exist
-- (color_accent, typography_font_family, border_thickness, theme_mode,
-- theme_night_shift_auto), so only text_size / spacing / corners are new.
-- border_thickness keeps its column and changes vocabulary instead — see
-- 20260809090000, which must run first.
--
-- WHAT ENDS HERE
-- --------------
-- 1. THE PROMOTION MODEL. Every token carried its own nullable column so any
--    one of them "could become a per-user override later" (the phrase recurs
--    through the design system's types.ts). It never happened. On dev, 31 of
--    the 52 columns dropped below hold nothing at all; the rest hold values on
--    one to three rows out of 36.
-- 2. THE AUTO/MANUAL GRAMMAR. There is no "null means follow the sector
--    preset" tier any more. Sector presets are a starting default at signup,
--    not a living link, so nothing is an override of anything.
-- 3. THE THREE effect_* AXES (brief §3.1). shadow / link / image treatment
--    stop being choices: shadow becomes the opt-in `.floating` class, link and
--    image each get one fixed treatment baked into the components.
-- 4. THE PRE-EXISTING ORPHANS (brief §8.3), absorbed rather than left behind:
--    the motion_* group, layout_density, space_desktop_*, and the icon columns
--    whose names never matched the TypeScript at all.
--
-- ONE-WAY DOOR. The per-user values in these 52 columns are discarded. Dev was
-- audited before writing this (counts above); production is HELD by owner
-- decision and has not been read. Audit it before applying there.
--
-- WHY THE THREE NEW COLUMNS ARE NULLABLE WITH NO DEFAULT
-- ------------------------------------------------------
-- "Every option always carries an explicit value" is a statement about the
-- CONTROL, not about the row. Three reasons the column stays null:
--   • trg_create_empty_design_kit inserts `(site_id)` and nothing else for
--     every new site, so a NOT NULL column without a default would break site
--     creation outright.
--   • NULL already resolves to the package default everywhere it is read —
--     the pages app's mergeDesignKit() and EmailBrandDefaults both fill from
--     code-side defaults. A materialised 'default' renders identically.
--   • DesignRationaleService reads "non-null" as "the user set this by hand".
--     A column DEFAULT would make every site on the platform report three
--     manual overrides it never made, and flip the transparency line to
--     "Your design is set from your own choices." for everyone.
-- If the owner wants the values materialised anyway, the cheap route is
-- `ADD COLUMN … DEFAULT 'default'` (catalog-only on PG 11+, no rewrite) plus
-- a change to DesignRationaleService::manualColumns() — not a NOT NULL pass.
--
-- NO CHECK CONSTRAINTS. The four selection vocabularies live in
-- DesignKitValidationRules::designKitRules() alone, exactly as theme_mode's
-- single legal value has since 2026-08-06. The request layer is the only write
-- path that carries a vocabulary (DesignKitAutopilot and DesignKitAccentApplier
-- write color_accent only; DesignKitRestyleService replays autopilot's own
-- proposals). Adding CHECKs later means a NOT VALID → VALIDATE pair per
-- CONVENTIONS §2 **and** three new lockstep entries in
-- tests/Feature/Database/ConstraintVocabularyLockstepTest.php, which regexes
-- the IN (...) list straight out of the migration text.
--
-- LOCKING. DROP COLUMN and ADD COLUMN (no default, no rewrite) are both
-- catalog-only writes in Postgres, so ACCESS EXCLUSIVE is held for
-- microseconds even on a hot table. ONE ALTER TABLE with comma-separated
-- clauses rather than 55 statements: one lock acquisition, one catalog write.
-- site.design_kits is on the hot list, hence the short lock_timeout — if the
-- lock cannot be taken promptly this fails fast rather than queueing behind a
-- long transaction and blocking every writer behind it.
--
-- ORDERING. 20260809090000 re-expresses border_thickness first. This file must
-- not run before it.
--
-- ROLLBACK: STRUCTURE ONLY — the values are gone and cannot be restored from
-- anything in the database. The statement below re-creates the 52 columns as
-- empty and removes the 3 new ones. Supabase is on the Free plan (no PITR, no
-- managed backups), so the per-user values are recoverable only from the
-- partna-db-backup R2 dump if one was taken before this ran.
--   ALTER TABLE site.design_kits
--       DROP COLUMN IF EXISTS text_size,
--       DROP COLUMN IF EXISTS spacing,
--       DROP COLUMN IF EXISTS corners,
--       ADD COLUMN border_color text, ADD COLUMN border_radius text,
--       ADD COLUMN border_small_radius text, ADD COLUMN color_accent_contrast text,
--       ADD COLUMN color_contrasting_bg text, ADD COLUMN color_contrasting_text text,
--       ADD COLUMN color_placeholder text, ADD COLUMN color_secondary_text text,
--       ADD COLUMN color_text text, ADD COLUMN color_text_muted text,
--       ADD COLUMN effect_image_treatment text, ADD COLUMN effect_link_style text,
--       ADD COLUMN effect_shadow_style text, ADD COLUMN icon_color text,
--       ADD COLUMN icon_size text, ADD COLUMN icons_brand_logo_height text,
--       ADD COLUMN icons_large_size text, ADD COLUMN icons_large_stroke_width text,
--       ADD COLUMN icons_stroke_width text, ADD COLUMN icons_xl_size text,
--       ADD COLUMN icons_xxl_size text, ADD COLUMN layout_density text,
--       ADD COLUMN motion_expand_duration text, ADD COLUMN motion_fade_duration text,
--       ADD COLUMN motion_spin_duration text, ADD COLUMN motion_spring_curve text,
--       ADD COLUMN space_desktop_regular text, ADD COLUMN space_desktop_xl text,
--       ADD COLUMN space_large text, ADD COLUMN space_medium text,
--       ADD COLUMN space_regular text, ADD COLUMN space_s text,
--       ADD COLUMN space_xl text, ADD COLUMN space_xs text,
--       ADD COLUMN space_xxs text, ADD COLUMN text_body text,
--       ADD COLUMN text_caption text, ADD COLUMN text_desktop_body text,
--       ADD COLUMN text_desktop_display text, ADD COLUMN text_desktop_h1 text,
--       ADD COLUMN text_display text, ADD COLUMN text_h1 text,
--       ADD COLUMN text_h2 text, ADD COLUMN text_h3 text,
--       ADD COLUMN typography_line_height text, ADD COLUMN typography_logo_height text,
--       ADD COLUMN weight_bold text, ADD COLUMN weight_heading text,
--       ADD COLUMN weight_light text, ADD COLUMN weight_medium text,
--       ADD COLUMN weight_regular text, ADD COLUMN weight_semibold text;
--   (border_thickness's lengths are not recoverable — see 20260809090000.)
--
-- AFTER THIS LANDS, in the same change set:
--   • config('partna.design_kit_columns_version') is bumped, or writeDesignKit()
--     filters against a cached PRE-drop column list for up to an hour: writes
--     to the three new columns silently discarded, writes to dropped ones
--     attempted. Bumped to 2026-08-09.1 in config/partna.php.
--   • config('partna.design_kit.column_groups.exact_columns') registers
--     text_size / spacing / corners / border_thickness. `spacing` and `corners`
--     carry no underscore at all, so groupKitColumns()'s prefix split cannot
--     see them and they would be dropped from the public payload silently.

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."design_kits"
    DROP COLUMN IF EXISTS "border_color",
    DROP COLUMN IF EXISTS "border_radius",
    DROP COLUMN IF EXISTS "border_small_radius",
    DROP COLUMN IF EXISTS "color_accent_contrast",
    DROP COLUMN IF EXISTS "color_contrasting_bg",
    DROP COLUMN IF EXISTS "color_contrasting_text",
    DROP COLUMN IF EXISTS "color_placeholder",
    DROP COLUMN IF EXISTS "color_secondary_text",
    DROP COLUMN IF EXISTS "color_text",
    DROP COLUMN IF EXISTS "color_text_muted",
    DROP COLUMN IF EXISTS "effect_image_treatment",
    DROP COLUMN IF EXISTS "effect_link_style",
    DROP COLUMN IF EXISTS "effect_shadow_style",
    DROP COLUMN IF EXISTS "icon_color",
    DROP COLUMN IF EXISTS "icon_size",
    DROP COLUMN IF EXISTS "icons_brand_logo_height",
    DROP COLUMN IF EXISTS "icons_large_size",
    DROP COLUMN IF EXISTS "icons_large_stroke_width",
    DROP COLUMN IF EXISTS "icons_stroke_width",
    DROP COLUMN IF EXISTS "icons_xl_size",
    DROP COLUMN IF EXISTS "icons_xxl_size",
    DROP COLUMN IF EXISTS "layout_density",
    DROP COLUMN IF EXISTS "motion_expand_duration",
    DROP COLUMN IF EXISTS "motion_fade_duration",
    DROP COLUMN IF EXISTS "motion_spin_duration",
    DROP COLUMN IF EXISTS "motion_spring_curve",
    DROP COLUMN IF EXISTS "space_desktop_regular",
    DROP COLUMN IF EXISTS "space_desktop_xl",
    DROP COLUMN IF EXISTS "space_large",
    DROP COLUMN IF EXISTS "space_medium",
    DROP COLUMN IF EXISTS "space_regular",
    DROP COLUMN IF EXISTS "space_s",
    DROP COLUMN IF EXISTS "space_xl",
    DROP COLUMN IF EXISTS "space_xs",
    DROP COLUMN IF EXISTS "space_xxs",
    DROP COLUMN IF EXISTS "text_body",
    DROP COLUMN IF EXISTS "text_caption",
    DROP COLUMN IF EXISTS "text_desktop_body",
    DROP COLUMN IF EXISTS "text_desktop_display",
    DROP COLUMN IF EXISTS "text_desktop_h1",
    DROP COLUMN IF EXISTS "text_display",
    DROP COLUMN IF EXISTS "text_h1",
    DROP COLUMN IF EXISTS "text_h2",
    DROP COLUMN IF EXISTS "text_h3",
    DROP COLUMN IF EXISTS "typography_line_height",
    DROP COLUMN IF EXISTS "typography_logo_height",
    DROP COLUMN IF EXISTS "weight_bold",
    DROP COLUMN IF EXISTS "weight_heading",
    DROP COLUMN IF EXISTS "weight_light",
    DROP COLUMN IF EXISTS "weight_medium",
    DROP COLUMN IF EXISTS "weight_regular",
    DROP COLUMN IF EXISTS "weight_semibold",
    ADD COLUMN IF NOT EXISTS "text_size" "text",
    ADD COLUMN IF NOT EXISTS "spacing" "text",
    ADD COLUMN IF NOT EXISTS "corners" "text";

COMMENT ON COLUMN "site"."design_kits"."text_size" IS
    'Selection: small | medium | large. Drives the five font roles and the five icon sizes (design-system TEXT_SIZE_PRESETS). Shown as "Default" for medium in the UI. NULL = package default.';
COMMENT ON COLUMN "site"."design_kits"."spacing" IS
    'Selection: default | spacious (+10%). Drives the four gaps, three paddings and three card heights (design-system SPACING_PRESETS). NULL = package default.';
COMMENT ON COLUMN "site"."design_kits"."corners" IS
    'Selection: default (8px) | rounded (16px). Drives --dk-radius; --dk-radius-button is deliberately NOT stepped by it (design-system CORNER_PRESETS). NULL = package default.';
COMMENT ON COLUMN "site"."design_kits"."border_thickness" IS
    'Selection: default (1px) | none (0) since 2026-08-09 — was a raw CSS length. Resolved through the design-system THICKNESS_PRESETS. NULL = package default.';

COMMIT;
