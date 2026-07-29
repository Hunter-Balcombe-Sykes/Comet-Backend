-- #PRIV-3: drives content:prune-orphaned-review-pii's cutoff predicate.
-- content.f_review carries NO index today (20260727140000_content_schema.sql:307-317),
-- so the prune would seq-scan the whole facet table on every daily run.
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_f_review_updated_at"
    ON "content"."f_review" ("updated_at");
