-- #MIG-3. One of four replacement indexes for the surface_key identity move
-- (20260727110000..110008 family). See 20260727110004 for why the drops are
-- implicit (DROP COLUMN "platform" already removed the old index).
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_platform_connections_unique_active"
    ON "site"."platform_connections" ("user_id", "surface_key", "resource_id")
    WHERE ("deleted_at" IS NULL);
