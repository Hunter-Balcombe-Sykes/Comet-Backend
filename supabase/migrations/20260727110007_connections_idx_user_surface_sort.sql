-- #MIG-3. Three of four replacement indexes — see 20260727110005.
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS site.idx_platform_connections_user_surface_sort;
--           in its own one-statement file, no BEGIN/COMMIT (CONVENTIONS.md §1).
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_platform_connections_user_surface_sort"
    ON "site"."platform_connections" ("user_id", "surface_key", "sort_order")
    WHERE ("deleted_at" IS NULL);
