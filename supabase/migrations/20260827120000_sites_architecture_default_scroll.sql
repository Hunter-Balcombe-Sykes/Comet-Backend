-- scroll becomes the platform default architecture (owner, 2026-08-27):
-- every account renders the scroll layout unless it picks otherwise. Two
-- halves, deliberately in one file because the owner's ask is "all
-- accounts", not just new ones:
--
--   1. The column DEFAULT flips 'staple' -> 'scroll' (catalog-only, no
--      table scan) so every future site provisions as scroll
--      (SiteProvisioningService relies on the DB default).
--   2. Every EXISTING row moves to 'scroll'. architecture_id carries no
--      provenance column, so there is no way to spare a deliberate staple
--      pick — acceptable because (owner, overnight run) all current rows
--      are test data, and the dashboard's Site page can switch any site
--      back. Application-side mirror: Site::DEFAULT_ARCHITECTURE_ID.
--
-- site.sites is hot (CONVENTIONS.md §8) but small at this stage; the
-- UPDATE runs under the standard short timeouts.
--
-- ROLLBACK:
--   ALTER TABLE site.sites ALTER COLUMN architecture_id SET DEFAULT 'staple';
--   UPDATE site.sites SET architecture_id = 'staple';
--   (only faithful while no site has deliberately chosen scroll since.)

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "site"."sites" ALTER COLUMN architecture_id SET DEFAULT 'scroll';

UPDATE "site"."sites" SET architecture_id = 'scroll'
 WHERE architecture_id IS DISTINCT FROM 'scroll';

COMMIT;
