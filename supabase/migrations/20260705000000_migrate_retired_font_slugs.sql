-- =====================================================================
-- Migrate the 4 retired font slugs to their spec-mapped replacements
-- =====================================================================
-- forma-djr / helvetica-neue / neue-haas-grotesk / nb-architekt were
-- replaced by the 17-font roster (docs/design/font-icon-knowledge.md §4
-- value-migration map). registry.ts's RETIRED_FONT_MIGRATION already
-- resolves these correctly at render time even if left unmigrated, so
-- this is data hygiene, not a live-rendering fix — but there's no reason
-- to leave orphaned slug strings sitting in stored rows once the retired
-- values can never be selected again.
--
-- No BEGIN/COMMIT — plain data backfill per CONVENTIONS.md §5. Both
-- tables are small (one row per site / per site-factor-column), so no
-- lock-contention concern justifying a batched approach.

UPDATE site.design_kits
SET typography_font_family = 'work-sans'
WHERE typography_font_family = 'forma-djr';

UPDATE site.design_kits
SET typography_font_family = 'tex-gyre-heros'
WHERE typography_font_family = 'helvetica-neue';

UPDATE site.design_kits
SET typography_font_family = 'tex-gyre-heros'
WHERE typography_font_family = 'neue-haas-grotesk';

UPDATE site.design_kits
SET typography_font_family = 'office-code-pro'
WHERE typography_font_family = 'nb-architekt';

UPDATE site.design_kit_contributions
SET value = 'work-sans'
WHERE target_var = 'typography_font_family' AND value = 'forma-djr';

UPDATE site.design_kit_contributions
SET value = 'tex-gyre-heros'
WHERE target_var = 'typography_font_family' AND value = 'helvetica-neue';

UPDATE site.design_kit_contributions
SET value = 'tex-gyre-heros'
WHERE target_var = 'typography_font_family' AND value = 'neue-haas-grotesk';

UPDATE site.design_kit_contributions
SET value = 'office-code-pro'
WHERE target_var = 'typography_font_family' AND value = 'nb-architekt';
