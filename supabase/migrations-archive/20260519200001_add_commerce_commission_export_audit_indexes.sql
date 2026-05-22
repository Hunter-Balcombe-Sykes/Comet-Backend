-- 20260519100001_add_commerce_commission_export_audit_indexes.sql
-- Per CONVENTIONS.md: index DDL lives in a separate file, no transaction wrapper,
-- CREATE INDEX CONCURRENTLY. (Table is empty at apply time; the convention still
-- applies for consistency and future safety if columns are backfilled.)

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cea_dedup
    ON commerce.commission_export_audit (professional_id, role, format, status, created_at DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cea_pro_created
    ON commerce.commission_export_audit (professional_id, created_at DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cea_status_processing_at
    ON commerce.commission_export_audit (status, processing_at)
    WHERE status = 'processing';

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cea_expires_at
    ON commerce.commission_export_audit (expires_at)
    WHERE expires_at IS NOT NULL AND file_path IS NOT NULL;
