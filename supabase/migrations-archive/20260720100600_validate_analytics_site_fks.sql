-- SCHEMA-3 — VALIDATE the two site_id FKs added NOT VALID by
-- 20260720100500_analytics_site_fks.sql. Kept in a separate file (rather than
-- a third window in that migration) so validation can be deferred
-- independently per-environment if a future environment turns out to have
-- orphans this dev census didn't catch — see that file's header for the full
-- rationale.
--
-- Dev census (glncumufgaqcmqhzwrxm, 2026-07-20) showed 0 orphans on both
-- tables, so both VALIDATE statements are expected to be a clean pass here.

-- Window 1 (item_views): VALIDATE CONSTRAINT acquires only SHARE UPDATE
-- EXCLUSIVE, so concurrent reads/writes continue throughout.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.item_views VALIDATE CONSTRAINT item_views_site_fk;

COMMIT;

-- Window 2 (content_popularity_scores): same shape, separate transaction.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE analytics.content_popularity_scores VALIDATE CONSTRAINT content_popularity_scores_site_fk;

COMMIT;

-- ROLLBACK:
-- No-op. VALIDATE CONSTRAINT is not reversible in isolation; to fully roll
-- back, drop the constraints added in 20260720100500_analytics_site_fks.sql
-- (see that file's ROLLBACK comment).
