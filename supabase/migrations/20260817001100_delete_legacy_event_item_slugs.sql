-- Slice 7 Phase 6 — delete the legacy event slugs from site.item_slugs.
--
-- DML, not DDL: site.item_slugs is NOT dropped by this slice. Its menu_item
-- rows are left in place, inert.
--
-- Why these rows can go, and why now. The last READER of the legacy event slugs
-- moved in slice 2 Task 9: eventbrite/humanitix/events-custom carry an empty
-- public allowlist, events reach the wire through profile.pools.events, and
-- PoolResolver serves their slug/aliases from content.item_slugs
-- (ContentItemSlugAllocator::SLUGGED_KINDS carries 'event').
--
-- The WRITER outlived the reader by five days. EventSlugSync, called from
-- IntegrationConnectionObserver on every connect and refresh, kept minting rows
-- into a registry nothing read — so deleting them before retiring it would have
-- been undone by the next Eventbrite refresh. It is retired in the same change
-- as this migration, which is what makes the delete stick.
--
-- Scoped to item_type='event' — the menu_item rows are a separate concern and
-- this file has one.

BEGIN;

DELETE FROM site.item_slugs WHERE item_type = 'event';

COMMIT;
