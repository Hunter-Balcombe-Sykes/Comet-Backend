-- Atlas — the 7th skeleton (#30), a multi-page Business website. This only
-- WIDENS the skeleton_id enum to accept 'atlas'; Business-only SELECTION is
-- enforced in the app layer (UpdateSiteRequest gates on the
-- can_use_multipage_site capability), not the DB CHECK, so a business can pick
-- it and the renderer accepts it. No new columns, no data remap.
--
-- Two-step CHECK swap per CONVENTIONS.md §2 and the prior skeleton_id CHECK
-- migrations (20260603000002, 20260707030000, 20260707080000, 20260707120000):
-- drop + re-add NOT VALID (brief metadata lock), then VALIDATE separately.

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('bento', 'dock', 'flick', 'deck', 'sheet', 'thread', 'atlas')) NOT VALID;

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;
