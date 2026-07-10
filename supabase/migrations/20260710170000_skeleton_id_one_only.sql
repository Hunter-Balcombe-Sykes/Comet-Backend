-- Skeleton collapse to 'one' (2026-07-10).
-- The platform is single-skeleton: the bento/dock/flick/deck/atlas skeletons
-- were deleted from apps/pages and the dashboard picker removed. The API layer
-- normalizes every historical id to 'one' on write (UpdateSiteRequest
-- LEGACY_SKELETON_IDS), so the column can now be constrained to the one value
-- that renders.

-- Belt: normalize any straggler rows (already done operationally on dev).
update site.sites set skeleton_id = 'one' where skeleton_id <> 'one';

-- New sites start on the only layout that exists.
alter table site.sites alter column skeleton_id set default 'one';

-- Tighten the CHECK from the six-id era to the single canonical value.
-- NOT VALID + VALIDATE per CONVENTIONS.md §2 (lock-light on populated tables).
alter table site.sites drop constraint if exists sites_skeleton_id_check;
alter table site.sites
    add constraint sites_skeleton_id_check check (skeleton_id = 'one') not valid;
alter table site.sites validate constraint sites_skeleton_id_check;
