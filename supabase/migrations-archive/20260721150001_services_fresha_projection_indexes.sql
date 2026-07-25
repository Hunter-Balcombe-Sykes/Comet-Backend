-- Companion to 20260721150000 — index file per CONVENTIONS.md §1 (CONCURRENTLY
-- cannot run inside a transaction; site.services is live + populated).
-- One LIVE projection per (user, Fresha serviceId) — the dedup guarantee.
-- Trashed rows are excluded so a suppression record (deleted_origin='user')
-- can coexist with nothing (a suppressed id is never re-created live).
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS services_user_fresha_external_uq
    ON site.services (user_id, external_id)
    WHERE source = 'fresha' AND deleted_at IS NULL;
