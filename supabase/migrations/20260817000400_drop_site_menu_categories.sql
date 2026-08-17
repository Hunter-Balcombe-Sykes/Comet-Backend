-- Slice 7 Phase 6 — legacy teardown, 4 of 4 menu tables. Parent last.
--
-- site.menu_categories' content.* twin is content.collections kind
-- 'menu_category', keyed (user_id, kind, external_ref) with the ref derived
-- from the LABEL (MenuProjectionMapper::categoryRef).
--
-- Two things went with this table rather than being re-homed, both deliberate
-- and both recorded in the wire manifest:
--   * `source_platform` — content.collections carries no source column, so the
--     dashboard's sync-detach warning has nothing to key off. MenuFetchJob's
--     ownerSource() now derives scan/website-scan from the category ref and
--     degrades to scan/manual where no ref names a source.
--   * MenuObserver's DINT-102 soft-delete guard, whose whole duty was refusing
--     to orphan rows in THIS table. With it gone the orphan cannot exist.
--
-- ACCEPTED LOSS, owner ruling 2026-08-17: 2 categories on `ollies` from the
-- same 2026-08-16 23:03:42+00 scrape exist only here.
--
-- No IF EXISTS — fail loudly on schema drift rather than silently skipping.

BEGIN;

DROP TABLE site.menu_categories;

COMMIT;
