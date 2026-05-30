-- F7 (P1): PromoteCleanMediaJob runs every ~60s with
--   WHERE processing_state='scanning' AND scanned_at IS NULL ORDER BY created_at ASC LIMIT 100
-- With no supporting index this is a recurring seqscan on site.site_media; at
-- 100k sites / millions of media rows it does not scale. This partial index
-- matches the query exactly and stays tiny — rows leave it as they transition
-- to 'ready'/'quarantined'.
--
-- Index file: CONCURRENTLY, no BEGIN/COMMIT (CONVENTIONS.md §1).

CREATE INDEX CONCURRENTLY IF NOT EXISTS site_media_scanning_idx
    ON site.site_media (created_at)
    WHERE processing_state = 'scanning' AND scanned_at IS NULL;
