-- Slice 7 Phase 6 — retire site.content_selection (unit E).
--
-- The ordered "Content Selection" (<=15 picks) and its four owner verbs were
-- retired under the 2026-08-14 owner override: media curation is pool:media
-- pins now (site.section_items), and the profile payload's gallery /
-- designMedia / siteImages keys were deleted outright rather than dual-served.
--
-- ContentSelectionService, the ContentSelection model and ContentSelectionPolicy
-- are already gone; ContentSelectionMigrator and content:migrate-content-selection
-- retire with this migration. Nothing in app/ reads or writes the table.
--
-- Carried, not migrated (checkpoint §15, restated at 95 rows on 2026-08-17):
-- the 92 non-upload rows (85 google-photo, 4 ig-post, 3 ig-reel) are a
-- deliberate drop. The 3 `upload` rows were covered 3/3 and are pinned in
-- pool:media.
--
-- No IF EXISTS — fail loudly on schema drift rather than silently skipping.

BEGIN;

DROP TABLE site.content_selection;

COMMIT;
