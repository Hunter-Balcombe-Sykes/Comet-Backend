-- Enquiry inbox indexes — split from 20260527160000_enquiry_inbox.sql for CONCURRENTLY safety.
-- CREATE INDEX CONCURRENTLY cannot run inside a transaction (BEGIN/COMMIT). The parent file
-- handles all DDL column additions, FK constraints, and the DML backfill inside a transaction;
-- this file handles only the indexes, outside any transaction wrapper.
-- See supabase/migrations/CONVENTIONS.md §1.

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_user_status_created
    ON site.enquiries (user_id, status, created_at DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_customer
    ON site.enquiries (customer_id)
    WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_notification
    ON site.enquiries (notification_id)
    WHERE notification_id IS NOT NULL;
