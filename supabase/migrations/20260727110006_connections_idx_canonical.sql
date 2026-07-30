-- #MIG-3. Two of four replacement indexes — see 20260727110005.
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS site.idx_platform_connections_canonical;
--           in its own one-statement file, no BEGIN/COMMIT (CONVENTIONS.md §1).
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_platform_connections_canonical"
    ON "site"."platform_connections" ("user_id", "surface_key", "canonical_key")
    WHERE (("canonical_key" IS NOT NULL) AND ("deleted_at" IS NULL));
