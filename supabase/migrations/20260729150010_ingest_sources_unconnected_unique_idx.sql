CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_ingest_sources_unconnected_unique"
    ON "ingest"."sources" ("user_id", "source_key") WHERE ("connection_id" IS NULL);
