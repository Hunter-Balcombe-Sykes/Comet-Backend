-- Extends site_media.processing_state CHECK to allow 'scanning' and 'quarantined'.
-- Drops then re-adds NOT VALID; companion file 1 validates separately.

BEGIN;

ALTER TABLE site.site_media
    DROP CONSTRAINT IF EXISTS site_media_processing_state_check;

ALTER TABLE site.site_media
    ADD CONSTRAINT site_media_processing_state_check
    CHECK (processing_state IN ('pending', 'processing', 'scanning', 'ready', 'failed', 'quarantined'))
    NOT VALID;

ALTER TABLE site.site_media
    ADD COLUMN IF NOT EXISTS scanned_at TIMESTAMPTZ NULL;

COMMENT ON COLUMN site.site_media.scanned_at IS
    'Set when CSAM scan completes. NULL = pre-scanning-era media (grandfathered) or scan not yet run.';

COMMIT;
