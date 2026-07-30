-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS ingest.idx_ingest_sources_unconnected_unique;
--           in its own one-statement file, no BEGIN/COMMIT (CONVENTIONS.md §1).
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_ingest_sources_unconnected_unique"
    ON "ingest"."sources" ("user_id", "source_key") WHERE ("connection_id" IS NULL);
