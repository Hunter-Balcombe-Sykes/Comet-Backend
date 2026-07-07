-- Remove the thread + sheet skeletons (#78 — user directive, "dumb, don't want
-- them"). Remap any site on them to deck (the redesign's directional anchor)
-- first, then narrow the CHECK to the 5 kept skeletons (bento/dock/flick/deck/atlas).
-- Applied live to the dev DB (glncumufgaqcmqhzwrxm) 2026-07-08.
UPDATE site.sites SET skeleton_id = 'deck' WHERE skeleton_id IN ('thread', 'sheet');

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('bento', 'dock', 'flick', 'deck', 'atlas')) NOT VALID;

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;
