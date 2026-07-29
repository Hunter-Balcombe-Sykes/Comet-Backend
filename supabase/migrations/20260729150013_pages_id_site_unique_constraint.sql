-- Promote the concurrently-built index to a real UNIQUE constraint. Postgres's
-- FK planner does accept a bare unique index as a reference target, but the
-- documented contract is a unique/PK CONSTRAINT -- promoting removes any doubt
-- and makes the intent visible in \d site.pages. ADD CONSTRAINT ... USING INDEX
-- is a catalog-only operation: it takes ACCESS EXCLUSIVE but performs no scan
-- and no rebuild, and it renames the index to the constraint name.
--
-- ROLLBACK: ALTER TABLE site.pages DROP CONSTRAINT IF EXISTS pages_id_site_unique;
--           (drops the underlying index with it)

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "site"."pages"
    ADD CONSTRAINT "pages_id_site_unique" UNIQUE USING INDEX "idx_pages_id_site";

COMMIT;
