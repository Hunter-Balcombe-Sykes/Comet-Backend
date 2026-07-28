CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_effects_source"
    ON "ingest"."effects" ("source_id");
