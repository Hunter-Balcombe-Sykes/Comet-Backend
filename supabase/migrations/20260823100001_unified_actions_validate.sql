-- Validation half of 20260823100000_unified_actions.sql — its own transaction
-- so the catalog-write lock is not held through the row scan (CONVENTIONS §2).
--
-- ROLLBACK: none needed (validation only).
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '60s';
ALTER TABLE analytics.content_popularity_scores VALIDATE CONSTRAINT content_popularity_scores_content_type_check;
COMMIT;
