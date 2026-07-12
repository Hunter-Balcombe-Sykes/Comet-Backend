-- 20260712070000_seven_font_simplification.sql
--
-- Font system simplified to 7 single-font options (2026-07-12): geist, inter,
-- general-sans, monument-grotesk, forma-djr, helvetica-neue, helvetica-now.
-- The pairing catalog and the old 9/17-font rosters are gone. This migration
-- rewrites every stored typography_font_family that is NOT one of the 7 to its
-- closest survivor, byte-identical to RETIRED_FONT_MIGRATION in
-- @partnaau/design-system registry.ts and the Partna-Frontend map.
--
-- NOT URGENT: the render side (registry.ts fontEntryFor + read-time merge)
-- already resolves any legacy slug to its survivor, so live sitepages render
-- correctly before this runs. Apply only AFTER the design-system + apps/pages
-- deploy carrying the 7-font registry is live. Robust to merge order — the
-- catch-all collapses ANY non-7 value (9-roster OR 19-pairing slugs) to geist,
-- with the specific survivors applied first.
--
-- All 7 live fonts are sans, so serif/expressive-display picks have no
-- same-category survivor and collapse to the neutral default (geist);
-- Helvetica-lineage grotesques map to the two Helveticas; Switzer → Helvetica
-- Now (its modern-Helvetica analog).

-- Reversible-audit backup (drop once verified). Captures every row this touches.
CREATE TABLE IF NOT EXISTS site._font_backup_20260712070000 AS
SELECT site_id, typography_font_family, now() AS backed_up_at
FROM site.design_kits
WHERE typography_font_family IS NOT NULL
  AND typography_font_family NOT IN
      ('geist','inter','general-sans','monument-grotesk','forma-djr','helvetica-neue','helvetica-now');

-- ── site.design_kits — the stored per-user pick ──────────────────────────
-- Specific survivors first (closer than the default).
UPDATE site.design_kits SET typography_font_family = 'helvetica-neue'
 WHERE typography_font_family IN ('tex-gyre-heros', 'neue-haas-grotesk');
UPDATE site.design_kits SET typography_font_family = 'helvetica-now'
 WHERE typography_font_family IN ('switzer', 'switzer-general-sans');
UPDATE site.design_kits SET typography_font_family = 'general-sans'
 WHERE typography_font_family = 'general-sans-sentient';
UPDATE site.design_kits SET typography_font_family = 'monument-grotesk'
 WHERE typography_font_family = 'vercetti';
-- Catch-all: any remaining non-7 value → the default.
UPDATE site.design_kits SET typography_font_family = 'geist'
 WHERE typography_font_family IS NOT NULL
   AND typography_font_family NOT IN
       ('geist','inter','general-sans','monument-grotesk','forma-djr','helvetica-neue','helvetica-now');

-- ── site.design_kit_contributions — factor-emitted contributions ─────────
-- Same remap on the (target_var, value) rows so a re-resolve doesn't re-apply
-- a dead slug.
UPDATE site.design_kit_contributions SET value = 'helvetica-neue'
 WHERE target_var = 'typography_font_family' AND value IN ('tex-gyre-heros', 'neue-haas-grotesk');
UPDATE site.design_kit_contributions SET value = 'helvetica-now'
 WHERE target_var = 'typography_font_family' AND value IN ('switzer', 'switzer-general-sans');
UPDATE site.design_kit_contributions SET value = 'general-sans'
 WHERE target_var = 'typography_font_family' AND value = 'general-sans-sentient';
UPDATE site.design_kit_contributions SET value = 'monument-grotesk'
 WHERE target_var = 'typography_font_family' AND value = 'vercetti';
UPDATE site.design_kit_contributions SET value = 'geist'
 WHERE target_var = 'typography_font_family'
   AND value NOT IN ('geist','inter','general-sans','monument-grotesk','forma-djr','helvetica-neue','helvetica-now');

-- Down (manual): restore from site._font_backup_20260712070000 by site_id.
--   UPDATE site.design_kits d SET typography_font_family = b.typography_font_family
--   FROM site._font_backup_20260712070000 b WHERE b.site_id = d.site_id;
-- (Contributions are not backed up — they re-resolve from factors on the next
--  design-preset pass, so a restore of design_kits is sufficient.)
