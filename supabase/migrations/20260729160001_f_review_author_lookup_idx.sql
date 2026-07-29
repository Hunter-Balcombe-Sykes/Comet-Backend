-- #PRIV-3, second half: a reviewer's own APP 12/13 (or GDPR Art. 15/17)
-- request currently has NO lookup path — there is no way to find every row
-- mentioning a given reviewer. This index is that path: staff can answer
-- "what do you hold about me" with an indexed equality lookup on the folded
-- name instead of a full scan of every tenant's review facets.
-- Partial: rows with a NULL author_name are unclaimed-account records that
-- landed post-redaction and can never match a lookup.
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_f_review_author_lookup"
    ON "content"."f_review" (lower("author_name"))
    WHERE "author_name" IS NOT NULL;
