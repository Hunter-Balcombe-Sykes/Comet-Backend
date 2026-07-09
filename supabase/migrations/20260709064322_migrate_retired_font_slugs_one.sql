-- =====================================================================
-- Migrate the 13 font slugs retired in the ONE 17->9 roster swap
-- =====================================================================
-- The 9-font roster keeps reglo/oswald/ostrich-sans/young-serif and adds
-- melodrama/geist/parceh/humane/origin. These 13 are removed. Mapping is
-- the single source used identically in the design-system + frontend
-- RETIRED_FONT_MIGRATION maps and the font-icon knowledge base:
--   work-sans, roboto, tex-gyre-heros, mplus, quicksand, office-code-pro,
--   cooper-hewitt, archivo   -> geist
--   eb-garamond, libre-baskerville, playfair-display, zilla-slab -> origin
--   caveat -> melodrama
--
-- TIMING (plan Phase 8 step 6): APPLY THIS ONLY AFTER the design-system +
-- apps/pages deploys are live. Before it lands, stored retired slugs resolve
-- via RETIRED_FONT_MIGRATION at render time, so there is no broken window in
-- either direction. Do NOT apply during Phases 1-7.
--
-- No BEGIN/COMMIT — plain data backfill per CONVENTIONS.md §5 (both tables
-- are tiny: one row per site / per site-factor-column). Backup tables make
-- the lossy UPDATEs reversible; drop them only after V1 ships.
--
-- Down (restore):
--   UPDATE site.design_kits d SET typography_font_family = b.typography_font_family
--     FROM site.design_kits_font_backup_20260709 b WHERE b.id = d.id;
--   UPDATE site.design_kit_contributions c SET value = b.value
--     FROM site.design_kit_contributions_font_backup_20260709 b WHERE b.id = c.id;
--   DROP TABLE site.design_kits_font_backup_20260709;
--   DROP TABLE site.design_kit_contributions_font_backup_20260709;

-- Reversible backups of exactly the rows about to change.
CREATE TABLE IF NOT EXISTS site.design_kits_font_backup_20260709 AS
  SELECT id, typography_font_family
  FROM site.design_kits
  WHERE typography_font_family IN (
    'work-sans','roboto','tex-gyre-heros','mplus','quicksand','office-code-pro',
    'cooper-hewitt','archivo','eb-garamond','libre-baskerville','playfair-display',
    'zilla-slab','caveat'
  );

CREATE TABLE IF NOT EXISTS site.design_kit_contributions_font_backup_20260709 AS
  SELECT id, value
  FROM site.design_kit_contributions
  WHERE target_var = 'typography_font_family' AND value IN (
    'work-sans','roboto','tex-gyre-heros','mplus','quicksand','office-code-pro',
    'cooper-hewitt','archivo','eb-garamond','libre-baskerville','playfair-display',
    'zilla-slab','caveat'
  );

-- design_kits.typography_font_family
UPDATE site.design_kits SET typography_font_family = 'geist'
WHERE typography_font_family IN
  ('work-sans','roboto','tex-gyre-heros','mplus','quicksand','office-code-pro','cooper-hewitt','archivo');

UPDATE site.design_kits SET typography_font_family = 'origin'
WHERE typography_font_family IN
  ('eb-garamond','libre-baskerville','playfair-display','zilla-slab');

UPDATE site.design_kits SET typography_font_family = 'melodrama'
WHERE typography_font_family = 'caveat';

-- design_kit_contributions.value (target_var = 'typography_font_family')
UPDATE site.design_kit_contributions SET value = 'geist'
WHERE target_var = 'typography_font_family' AND value IN
  ('work-sans','roboto','tex-gyre-heros','mplus','quicksand','office-code-pro','cooper-hewitt','archivo');

UPDATE site.design_kit_contributions SET value = 'origin'
WHERE target_var = 'typography_font_family' AND value IN
  ('eb-garamond','libre-baskerville','playfair-display','zilla-slab');

UPDATE site.design_kit_contributions SET value = 'melodrama'
WHERE target_var = 'typography_font_family' AND value = 'caveat';
