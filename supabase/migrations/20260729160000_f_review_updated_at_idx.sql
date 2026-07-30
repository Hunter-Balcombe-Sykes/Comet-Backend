-- #PRIV-3: drives content:prune-orphaned-review-pii's cutoff predicate.
-- content.f_review carries NO index today (20260727140000_content_schema.sql:307-317),
-- so the prune would seq-scan the whole facet table on every daily run.
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS content.idx_f_review_updated_at;
--           in its own one-statement file, no BEGIN/COMMIT (CONVENTIONS.md §1).
--           Consequence: content:prune-orphaned-review-pii goes back to
--           seq-scanning the whole facet table on every daily run (#PRIV-3).
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_f_review_updated_at"
    ON "content"."f_review" ("updated_at");
