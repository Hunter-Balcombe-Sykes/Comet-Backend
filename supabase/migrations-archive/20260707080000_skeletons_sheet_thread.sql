-- Extend the skeleton_id CHECK constraint to include the two new skeletons
-- added by docs/superpowers/specs/2026-07-07-skeleton-expansion-design.md
-- section (b): 'sheet' (iOS springboard + bottom-sheet navigation) and
-- 'thread' (conversational feed). Neither has a legacy skeleton-N alias —
-- both postdate the 2026-07-07 skeleton-N -> named rename
-- (20260707030000_rename_skeleton_ids.sql).
--
-- Two-step CHECK swap per CONVENTIONS.md §2: drop + re-add NOT VALID (brief
-- metadata lock only), then VALIDATE in a separate transaction (SHARE UPDATE
-- EXCLUSIVE — doesn't block concurrent reads/writes). site.sites is small
-- pre-launch, but this follows the same pattern as the two prior skeleton_id
-- CHECK migrations (20260707030000, 20260603000002) rather than special-casing
-- "it's a small table" — the lint rule and the precedent both call for it.
--
-- NOTE: filename uses 20260707080000 rather than the 20260707050000 an
-- earlier draft of this task named, because 20260707050000 already exists
-- (20260707050000_design_kits_weight_light_scrim_blur.sql) — migration
-- timestamps must be unique/ordered, so this lands after the latest existing
-- migration in this repo at write time (20260707070000_platform_connections_
-- display_settings.sql).

BEGIN;

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('bento', 'hub', 'stories', 'flow', 'sheet', 'thread')) NOT VALID;

COMMIT;

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;
