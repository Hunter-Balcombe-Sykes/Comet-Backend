-- Expression index to accelerate the polling job's query:
--   WHERE block_group = 'links' AND settings->>'live_check_enabled' = 'true'
-- The blocks table differentiates links vs sections via block_group (NOT block_type).
-- Without this index, the job scans all link blocks on every 2-minute cycle.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_blocks_live_check_enabled
    ON site.blocks ((settings->>'live_check_enabled'))
    WHERE block_group = 'links' AND deleted_at IS NULL AND is_active = true;
