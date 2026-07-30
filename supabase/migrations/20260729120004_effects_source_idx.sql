-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS ingest.idx_effects_source;
--           in its own one-statement file, no BEGIN/COMMIT (CONVENTIONS.md §1).
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_effects_source"
    ON "ingest"."effects" ("source_id");
