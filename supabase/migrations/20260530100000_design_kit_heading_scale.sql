-- 20260530100000_design_kit_heading_scale.sql
--
-- Restructures the typography heading scale on site.design_kits to
-- match @partnaau/design-system 4.0.0's three heading roles:
--   h1 → page title (2.5rem / 600 default)
--   h2 → section title (1.1rem / 700 default; same values as the
--        previous title* fields, just renamed)
--   h3 → subsection label (body size / 600 weight, no kit vars
--        beyond the existing body fontSize)
--
-- Strategy:
--   - RENAME the existing typography_title_* columns to
--     typography_h2_* so any stored user customisations are
--     preserved (the values map 1:1 — old title* drove h1 which is
--     now h2 visually).
--   - ADD new typography_h1_font_size + typography_h1_font_weight
--     columns (nullable; defaults live in the @partnaau/design-system
--     package).
--   - RENAME typography_desktop_title_font_size →
--     typography_desktop_h2_font_size + add
--     typography_desktop_h1_font_size for symmetry.
--
-- Idempotent guards via DO blocks so the migration is safe to re-run
-- if a downstream env has already partially landed it.

BEGIN;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'site'
          AND table_name = 'design_kits'
          AND column_name = 'typography_title_font_size'
    ) THEN
        ALTER TABLE site.design_kits
            RENAME COLUMN typography_title_font_size TO typography_h2_font_size;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'site'
          AND table_name = 'design_kits'
          AND column_name = 'typography_title_font_weight'
    ) THEN
        ALTER TABLE site.design_kits
            RENAME COLUMN typography_title_font_weight TO typography_h2_font_weight;
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'site'
          AND table_name = 'design_kits'
          AND column_name = 'typography_desktop_title_font_size'
    ) THEN
        ALTER TABLE site.design_kits
            RENAME COLUMN typography_desktop_title_font_size TO typography_desktop_h2_font_size;
    END IF;
END $$;

ALTER TABLE site.design_kits
    ADD COLUMN IF NOT EXISTS typography_h1_font_size TEXT NULL,
    ADD COLUMN IF NOT EXISTS typography_h1_font_weight TEXT NULL,
    ADD COLUMN IF NOT EXISTS typography_desktop_h1_font_size TEXT NULL;

COMMIT;
