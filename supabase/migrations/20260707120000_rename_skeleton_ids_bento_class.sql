-- Bento-class skeleton renames (#41 step 0): hub → dock, stories → flick,
-- flow → deck. Physical-metaphor names users can see in the layout (the tab
-- bar IS a dock; the panels ARE a deck; flick = flipbook frames) replacing
-- the descriptive/borrowed ones. 'sheet' and 'thread' already carry
-- bento-class names and keep them; 'bento' stays.
--
-- Order of operations across the system: the pages dispatcher deployed FIRST
-- with dual acceptance (normalizeSkeletonId maps hub/stories/flow → the new
-- ids), so nothing breaks between this migration and the backend/frontend
-- deploys that follow it.
--
-- Two-step CHECK swap per CONVENTIONS.md §2 and the three prior skeleton_id
-- CHECK migrations (20260603000002, 20260707030000, 20260707080000): drop +
-- remap + re-add NOT VALID (brief metadata lock), then VALIDATE separately
-- (SHARE UPDATE EXCLUSIVE — no read/write blocking).

BEGIN;

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

UPDATE site.sites SET skeleton_id = CASE skeleton_id
    WHEN 'hub' THEN 'dock'
    WHEN 'stories' THEN 'flick'
    WHEN 'flow' THEN 'deck'
    ELSE skeleton_id
END
WHERE skeleton_id IN ('hub', 'stories', 'flow');

ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('bento', 'dock', 'flick', 'deck', 'sheet', 'thread')) NOT VALID;

COMMIT;

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;
