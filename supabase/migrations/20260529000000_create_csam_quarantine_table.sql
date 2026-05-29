-- moderation.csam_quarantine
-- Tracks quarantined uploads for the legally-required 90-day preservation window.
-- ON DELETE RESTRICT on case_id + site_media_id: these rows have legal preservation
-- obligations and can only be removed via the explicit expiry pathway.

BEGIN;

CREATE TABLE IF NOT EXISTS moderation.csam_quarantine (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id                  UUID NOT NULL,
    site_media_id            UUID NOT NULL,
    uploader_user_id         UUID NULL,
    uploader_ip_hash         VARCHAR(64) NULL,
    upload_user_agent        TEXT NULL,
    original_filename        VARCHAR(255) NULL,
    original_mime            VARCHAR(100) NULL,
    original_size_bytes      BIGINT NULL,
    content_hash             VARCHAR(128) NOT NULL,
    cloudflare_match_payload JSONB NOT NULL,
    r2_quarantine_key        TEXT NOT NULL,
    r2_binary_deleted        BOOLEAN NOT NULL DEFAULT FALSE,
    r2_binary_deleted_at     TIMESTAMPTZ NULL,
    preservation_expires_at  TIMESTAMPTZ NOT NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT csam_quarantine_case_fk FOREIGN KEY (case_id)
        REFERENCES moderation.cases(id) ON DELETE RESTRICT,
    CONSTRAINT csam_quarantine_site_media_fk FOREIGN KEY (site_media_id)
        REFERENCES site.site_media(id) ON DELETE RESTRICT,
    CONSTRAINT csam_quarantine_uploader_fk FOREIGN KEY (uploader_user_id)
        REFERENCES core.users(id) ON DELETE SET NULL
);

COMMIT;
