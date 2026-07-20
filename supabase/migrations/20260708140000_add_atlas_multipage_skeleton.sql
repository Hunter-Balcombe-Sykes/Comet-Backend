-- Atlas — the 7th skeleton (#30), a multi-page Business website. This only
-- WIDENS the skeleton_id enum to accept 'atlas'; Business-only SELECTION is
-- enforced in the app layer (UpdateSiteRequest gates on the
-- can_use_multipage_site capability), not the DB CHECK, so a business can pick
-- it and the renderer accepts it. No new columns, no data remap.
--
-- Two-step CHECK swap per CONVENTIONS.md §2 and the prior skeleton_id CHECK
-- migrations (20260603000002, 20260707030000, 20260707080000, 20260707120000):
-- drop + re-add NOT VALID (brief metadata lock), then VALIDATE separately.
--
-- The two steps need explicit BEGIN/COMMIT windows, not just sequential
-- statements: without them the whole file runs as one implicit transaction,
-- so the ACCESS EXCLUSIVE taken by DROP/ADD CONSTRAINT is held through the
-- VALIDATE scan too, defeating the NOT VALID optimisation (audit MIG-3).

-- Window 1: swap the CHECK in NOT VALID form. The ACCESS EXCLUSIVE taken for
-- the catalog writes is released at COMMIT, BEFORE the validation scan.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('bento', 'dock', 'flick', 'deck', 'sheet', 'thread', 'atlas')) NOT VALID;

COMMIT;

-- Window 2: validate in its own transaction. VALIDATE CONSTRAINT takes only
-- SHARE UPDATE EXCLUSIVE, so concurrent reads/writes on site.sites continue.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;

COMMIT;
