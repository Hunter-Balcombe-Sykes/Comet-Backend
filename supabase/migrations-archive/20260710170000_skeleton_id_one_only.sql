-- Skeleton collapse to 'one' (2026-07-10).
-- The platform is single-skeleton: the bento/dock/flick/deck/atlas skeletons
-- were deleted from apps/pages and the dashboard picker removed. The API layer
-- normalizes every historical id to 'one' on write (UpdateSiteRequest
-- LEGACY_SKELETON_IDS), so the column can now be constrained to the one value
-- that renders.
--
-- the normalize/default/CHECK-swap steps need explicit begin/commit windows,
-- not just sequential statements: without them the whole file runs as one
-- implicit transaction, so the access exclusive taken by drop/add constraint
-- is held through the validate scan too, defeating the not valid
-- optimisation (audit MIG-3).

-- window 1: normalize stragglers, set the new default, and swap the CHECK
-- in not valid form. the access exclusive taken for the catalog writes is
-- released at commit, before the validation scan.
begin;

set local lock_timeout      = '2s';
set local statement_timeout = '10s';

-- Belt: normalize any straggler rows (already done operationally on dev).
update site.sites set skeleton_id = 'one' where skeleton_id <> 'one';

-- New sites start on the only layout that exists.
alter table site.sites alter column skeleton_id set default 'one';

-- Tighten the CHECK from the six-id era to the single canonical value.
alter table site.sites drop constraint if exists sites_skeleton_id_check;
alter table site.sites
    add constraint sites_skeleton_id_check check (skeleton_id = 'one') not valid;

commit;

-- window 2: validate in its own transaction. validate constraint takes only
-- share update exclusive, so concurrent reads/writes on site.sites continue.
begin;

set local lock_timeout      = '2s';
set local statement_timeout = '10s';

alter table site.sites validate constraint sites_skeleton_id_check;

commit;
