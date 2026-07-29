-- #PRIV-4: lets site:prune-document-versions bound its candidate scan by age
-- before doing any per-site version arithmetic. idx_site_documents_current
-- (site_id, channel, version DESC) serves the per-site window but cannot
-- answer "which sites have anything old enough to prune" without a full scan.
CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_site_documents_built_at"
    ON "site"."site_documents" ("built_at");
