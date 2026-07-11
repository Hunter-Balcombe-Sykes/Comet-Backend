-- =====================================================================
-- Migrate the 'origin' font slug, retired in the 2026-07-12 pairing-
-- catalog rework, to its replacement 'sentient'
-- =====================================================================
-- Origin's licence was a commercial (Displaay Type Foundry) purchase that
-- was never made — it shipped as an eval/personal binary the whole time it
-- was live. Sentient (Fontshare, free-commercial) fills the same "serif
-- sink" role in the matcher + category presets, so this is a straight
-- 1:1 swap, not a fallback-to-default collapse. Mapping is the single
-- source used identically in the design-system RETIRED_FONT_MIGRATION map,
-- the Partna-Frontend map, and the font-icon knowledge base:
--   origin -> sentient
--
-- TIMING: apply only after the design-system + apps/pages deploys reading
-- the new FONT_PAIRINGS catalog are live. Before it lands, any stored
-- 'origin' resolves via RETIRED_FONT_MIGRATION at render time, so there is
-- no broken window in either direction.
--
-- No BEGIN/COMMIT — plain data backfill per CONVENTIONS.md §5 (both tables
-- are tiny: one row per site / per site-factor-column). Backup tables make
-- the lossy UPDATEs reversible; drop them only after this ships to prod.
--
-- Down (restore):
--   UPDATE site.design_kits d SET typography_font_family = b.typography_font_family
--     FROM site.design_kits_font_backup_20260712 b WHERE b.id = d.id;
--   UPDATE site.design_kit_contributions c SET value = b.value
--     FROM site.design_kit_contributions_font_backup_20260712 b WHERE b.id = c.id;
--   DROP TABLE site.design_kits_font_backup_20260712;
--   DROP TABLE site.design_kit_contributions_font_backup_20260712;

-- Reversible backups of exactly the rows about to change.
CREATE TABLE IF NOT EXISTS site.design_kits_font_backup_20260712 AS
  SELECT id, typography_font_family
  FROM site.design_kits
  WHERE typography_font_family = 'origin';

CREATE TABLE IF NOT EXISTS site.design_kit_contributions_font_backup_20260712 AS
  SELECT id, value
  FROM site.design_kit_contributions
  WHERE target_var = 'typography_font_family' AND value = 'origin';

-- design_kits.typography_font_family
UPDATE site.design_kits SET typography_font_family = 'sentient'
WHERE typography_font_family = 'origin';

-- design_kit_contributions.value (target_var = 'typography_font_family')
UPDATE site.design_kit_contributions SET value = 'sentient'
WHERE target_var = 'typography_font_family' AND value = 'origin';
