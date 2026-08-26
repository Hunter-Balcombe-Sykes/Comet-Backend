-- Serves replaceCollections()' origin-scoped DELETE on content.offers
-- (spec 2026-08-26 §5.1): `item_id IN (batch) AND source_id = ours AND
-- (source_item_id IS NULL OR source_item_id NOT IN (preserved))`.
--
-- ONE statement, alone in its file, on purpose. CONCURRENTLY cannot run in a
-- libpq pipeline, and the CLI pipelines any file with more than one statement
-- (SQLSTATE 25001) — CONVENTIONS.md §1, guard-no-unsafe-migrations check 6.
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS "content"."idx_offers_origin";
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_offers_origin" ON "content"."offers" ("item_id", "source_item_id");
