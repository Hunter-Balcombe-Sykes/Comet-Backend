-- Widen sites_architecture_id_check to allow the new 'scroll' architecture
-- (owner, 2026-08-24) — the second layout, reopening a value the platform
-- deliberately locked down to one ('staple') on 2026-07-15. Every existing
-- row is already 'staple', so this changes nothing on disk; it only lifts
-- the ceiling for new writes (Site::ARCHITECTURE_IDS / UpdateSiteRequest
-- carry the same pair, application-side).
--
-- NOT VALID + a separate VALIDATE (CONVENTIONS.md §2): site.sites is a hot
-- table (§8's list), so the DROP+ADD here stays catalog-only and the actual
-- per-row scan happens in the follow-up file under its own short lock.
--
-- ROLLBACK:
--   ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_architecture_id_check;
--   ALTER TABLE site.sites ADD CONSTRAINT sites_architecture_id_check
--       CHECK (architecture_id = 'staple'::text) NOT VALID;
--   (would need its own VALIDATE follow-up, and only holds if no row was
--   ever written as 'scroll' in the meantime.)

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "site"."sites" DROP CONSTRAINT IF EXISTS sites_architecture_id_check;
ALTER TABLE "site"."sites" ADD CONSTRAINT sites_architecture_id_check
    CHECK (architecture_id = ANY (ARRAY['staple'::text, 'scroll'::text])) NOT VALID;

COMMIT;
