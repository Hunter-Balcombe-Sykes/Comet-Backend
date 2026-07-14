-- Aesthetic-adaptivity axes (sitepage rebuild, 2026-07-15).
--
-- Three new design-kit vars (see the improvements plan §E):
--   typography_tracking — SELECTION tight|normal|wide; drives the derived
--     --dk-tracking-body + the --dk-label-tracking override.
--   theme_contrast — SELECTION soft|normal|stark; the dispatcher transforms
--     the theme-mode palette variants server-side (border/secondary toward bg
--     for soft, toward text for stark) + scales the muted/placeholder mixes.
--   weight_heading — VALUE; heading/display weight independent of body
--     (package default '500' — the ALD regular-body/medium-heading pairing).
--
-- NULLABLE, no DB defaults — code-side defaults live in the design-system
-- package (defaults.ts / vars.css), per the design-kit var contract.

ALTER TABLE site.design_kits
    ADD COLUMN IF NOT EXISTS typography_tracking TEXT NULL,
    ADD COLUMN IF NOT EXISTS theme_contrast TEXT NULL,
    ADD COLUMN IF NOT EXISTS weight_heading TEXT NULL;
