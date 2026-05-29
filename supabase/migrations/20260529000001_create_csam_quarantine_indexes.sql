-- Non-concurrent form for migration pipeline compatibility.
-- On live databases these can be re-created CONCURRENTLY if downtime is a concern,
-- but for initial provisioning the standard form is fine.

CREATE UNIQUE INDEX IF NOT EXISTS csam_quarantine_site_media_uniq
    ON moderation.csam_quarantine (site_media_id);

CREATE INDEX IF NOT EXISTS csam_quarantine_preservation_idx
    ON moderation.csam_quarantine (preservation_expires_at)
    WHERE r2_binary_deleted = FALSE;

CREATE INDEX IF NOT EXISTS csam_quarantine_uploader_idx
    ON moderation.csam_quarantine (uploader_user_id)
    WHERE uploader_user_id IS NOT NULL;
