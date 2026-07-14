-- Architecture swap: ONE → STAPLE (sitepage rebuild, 2026-07-15).
--
-- The platform stays single-architecture; the single architecture is now
-- 'staple' (a standard multi-page layout) instead of 'one' (the vertical
-- scroll-snap deck, deleted in partna-monorepo). Same shape as the 2026-07-10
-- skeleton_id_one_only collapse: update stored rows, retarget the column
-- DEFAULT, and re-pin the CHECK constraint. The request layer
-- (UpdateSiteRequest::LEGACY_ARCHITECTURE_IDS) now collapses 'one' — like all
-- earlier generations — to 'staple', so no client 422s on stale values.
--
-- The site.public_site_payload view's JSONB wire key `skeleton_id` is
-- untouched (value flows from the column; renaming that public wire key stays
-- a separate consumer-coordinated change — see 20260710230000).
--
-- Exempt from the ADD CONSTRAINT CHECK NOT VALID rule (guard:no-unsafe-
-- migrations, Master Pattern 20): site.sites has 10 rows total in dev (a
-- complete census, matching site.workplaces at the same tiny scale), so the
-- CHECK rebuild below takes a harmless, sub-millisecond lock — same
-- justification class as 20260612100000/20260629120000's platform_connections
-- exemptions. The UPDATE two statements above also already normalizes every
-- row to 'staple' first, so the CHECK can never fail validation.
--
-- guard:no-unsafe-migrations:disable-file

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_architecture_id_check;

UPDATE site.sites
SET architecture_id = 'staple'
WHERE architecture_id IS DISTINCT FROM 'staple';

ALTER TABLE site.sites ALTER COLUMN architecture_id SET DEFAULT 'staple';

ALTER TABLE site.sites
    ADD CONSTRAINT sites_architecture_id_check CHECK (architecture_id = 'staple');
