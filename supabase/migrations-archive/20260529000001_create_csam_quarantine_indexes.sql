-- Index files use CONCURRENTLY so long-running builds don't block writes.
-- No BEGIN/COMMIT — CONCURRENTLY cannot run inside a transaction.

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_site_media_uniq
    ON moderation.csam_quarantine (site_media_id);

CREATE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_preservation_idx
    ON moderation.csam_quarantine (preservation_expires_at)
    WHERE r2_binary_deleted = FALSE;

CREATE INDEX CONCURRENTLY IF NOT EXISTS csam_quarantine_uploader_idx
    ON moderation.csam_quarantine (uploader_user_id)
    WHERE uploader_user_id IS NOT NULL;
