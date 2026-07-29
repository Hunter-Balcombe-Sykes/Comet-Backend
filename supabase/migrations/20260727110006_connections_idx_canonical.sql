-- #MIG-3. Two of four replacement indexes — see 20260727110005.
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_platform_connections_canonical"
    ON "site"."platform_connections" ("user_id", "surface_key", "canonical_key")
    WHERE (("canonical_key" IS NOT NULL) AND ("deleted_at" IS NULL));
