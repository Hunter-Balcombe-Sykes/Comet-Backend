-- Validate the relaxed CHECK in its own transaction (CONVENTIONS.md §2).
BEGIN;
ALTER TABLE site.site_media VALIDATE CONSTRAINT site_media_processing_state_check;
COMMIT;
