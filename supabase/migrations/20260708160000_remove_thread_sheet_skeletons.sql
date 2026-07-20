-- Remove the thread + sheet skeletons (#78 — user directive, "dumb, don't want
-- them"). Remap any site on them to deck (the redesign's directional anchor)
-- first, then narrow the CHECK to the 5 kept skeletons (bento/dock/flick/deck/atlas).
-- Applied live to the dev DB (glncumufgaqcmqhzwrxm) 2026-07-08.
--
-- The remap + CHECK swap need explicit BEGIN/COMMIT windows, not just
-- sequential statements: without them the whole file runs as one implicit
-- transaction, so the ACCESS EXCLUSIVE taken by DROP/ADD CONSTRAINT is held
-- through the VALIDATE scan too, defeating the NOT VALID optimisation
-- (audit MIG-3).

-- Window 1: remap the two retired ids, then swap the CHECK in NOT VALID
-- form. The ACCESS EXCLUSIVE taken for the catalog writes is released at
-- COMMIT, BEFORE the validation scan.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

UPDATE site.sites SET skeleton_id = 'deck' WHERE skeleton_id IN ('thread', 'sheet');

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('bento', 'dock', 'flick', 'deck', 'atlas')) NOT VALID;

COMMIT;

-- Window 2: validate in its own transaction. VALIDATE CONSTRAINT takes only
-- SHARE UPDATE EXCLUSIVE, so concurrent reads/writes on site.sites continue.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;

COMMIT;
