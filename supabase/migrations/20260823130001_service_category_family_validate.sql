-- Validation half of 20260823130000_service_category_family.sql — its own
-- transaction so the catalog-write lock is not held through the row scans
-- (CONVENTIONS §2).
--
-- ROLLBACK: none needed (validation only).
BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '60s';
ALTER TABLE analytics.content_popularity_scores VALIDATE CONSTRAINT content_popularity_scores_content_type_check;
ALTER TABLE analytics.item_views VALIDATE CONSTRAINT item_views_item_type_check;
COMMIT;
