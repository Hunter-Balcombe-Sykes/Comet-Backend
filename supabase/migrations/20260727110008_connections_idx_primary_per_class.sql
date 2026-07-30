-- #MIG-3. Four of four replacement indexes (plan §1 SetPrimary: one primary
-- CTA per routing class per user) — see 20260727110005.
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS site.idx_platform_connections_primary_per_class;
--           in its own one-statement file, no BEGIN/COMMIT (CONVENTIONS.md §1).
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_platform_connections_primary_per_class"
    ON "site"."platform_connections" ("user_id", "routing_class")
    WHERE ("is_primary" AND "deleted_at" IS NULL);
