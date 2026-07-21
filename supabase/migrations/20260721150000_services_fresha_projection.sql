-- =====================================================================
-- Fresha services → site.services projection (2026-07-21)
-- =====================================================================
-- Fresha's scraped service menu used to live ONLY as a verbatim JSONB blob on
-- site.platform_connections.payload.selection.services — uneditable, and with
-- ZERO dedup (the same serviceId listed under two Fresha categories produced
-- two blob entries, i.e. duplicate services on the public booking list).
--
-- Booking & services rework: every effective Fresha service becomes a REAL
-- site.services row alongside the existing manual services, with menu-style
-- provenance semantics:
--   source      'fresha' = projected from the Fresha scrape; NULL = manual
--               (every pre-existing row — public behaviour unchanged: the
--               public services section and its visibility rules now filter
--               whereNull(source), so projections never leak into it).
--   is_manual   TRUE = the owner edited a projected row ("sync broken"): the
--               scheduled re-scrape no longer overwrites it, and the public
--               booking blob serialises the OWNER's version. Revert/resync
--               endpoints re-project from the stored raw scrape.
--   external_id the Fresha serviceId ('s:…'); the dedup + upsert identity.
--               Duplicate serviceIds in one scrape collapse to one row.
-- Deleting a projected row soft-deletes it with deleted_origin='user' — the
-- suppression record the sync consults so the service never resurrects
-- (PurgeSoftDeleted excludes these rows). A service that disappears from
-- Fresha is soft-deleted with deleted_origin='sync' and auto-restores if it
-- returns.
--
-- The public CDN contract is untouched: payload.selection still ships verbatim
-- (allowlisted), now COMPOSED from the projections (dedup + owner edits
-- included); the raw scrape moves to payload.raw, which the public allowlist
-- filters out. Existing connections migrate lazily — their first refresh /
-- reconnect / selection save populates projections + payload.raw; until then
-- they behave exactly as today.
--
-- site.service_categories.source: 'fresha' marks categories auto-created from
-- Fresha's category labels (find-or-create per user by lower(title); a trashed
-- same-title category means the owner deleted it — never resurrected).
--
-- The partial unique index (companion _indexes file, CONCURRENTLY) enforces
-- one live projection per (user, serviceId).

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE site.services
    ADD COLUMN IF NOT EXISTS source      text NULL CHECK (source IN ('fresha')),
    ADD COLUMN IF NOT EXISTS is_manual   boolean NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS external_id text NULL;

COMMENT ON COLUMN site.services.source IS
    '''fresha'' = projected from the Fresha scrape; NULL = owner-authored (manual). Public services section reads only NULL.';
COMMENT ON COLUMN site.services.is_manual IS
    'TRUE = owner edited a projected row (sync broken): the re-scrape never overwrites it; revert via /services/{id}/resync.';
COMMENT ON COLUMN site.services.external_id IS
    'Fresha serviceId (s:…) — projection identity; duplicate ids in one scrape collapse to one row.';

ALTER TABLE site.service_categories
    ADD COLUMN IF NOT EXISTS source text NULL CHECK (source IN ('fresha'));

COMMENT ON COLUMN site.service_categories.source IS
    '''fresha'' = auto-created from a Fresha category label during projection; NULL = owner-authored.';

COMMIT;

-- ROLLBACK:
-- ALTER TABLE site.services DROP COLUMN IF EXISTS source, DROP COLUMN IF EXISTS is_manual, DROP COLUMN IF EXISTS external_id;
-- ALTER TABLE site.service_categories DROP COLUMN IF EXISTS source;
