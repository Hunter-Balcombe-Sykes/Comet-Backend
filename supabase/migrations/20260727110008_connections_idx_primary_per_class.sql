-- #MIG-3. Four of four replacement indexes (plan §1 SetPrimary: one primary
-- CTA per routing class per user) — see 20260727110005.
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_platform_connections_primary_per_class"
    ON "site"."platform_connections" ("user_id", "routing_class")
    WHERE ("is_primary" AND "deleted_at" IS NULL);
