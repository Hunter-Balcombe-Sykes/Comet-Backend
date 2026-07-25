-- 20260615000001_smart_links_canonical_idx.sql
--
-- S3 (SCHEMA-4 / DINT-4) — one ACTIVE smart link per (site, canonical URL).
-- Split from 20260615000000 per CONVENTIONS.md §1: CONCURRENTLY cannot run
-- inside a transaction, so this file has NO BEGIN/COMMIT.
--
-- Partial on deleted_at so a previously-deleted URL can be re-added. Exact
-- canonical_url (NOT lower()) — URL paths are case-sensitive (e.g. Spotify
-- /track ids), so lower-casing would wrongly collapse distinct links.
--
-- If this fails on an existing duplicate (pre-beta: expected to be none), dedup
-- the offending active rows first, then re-run.
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uq_smart_links_site_canonical_active
    ON site.smart_links (site_id, canonical_url)
    WHERE deleted_at IS NULL;
