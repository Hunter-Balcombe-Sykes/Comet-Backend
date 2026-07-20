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

-- Drop enquiries_user_created_idx (user_id, created_at DESC). This drop is
-- inherited from the original 20260527160000 file, not a new decision — it was
-- moved here so it runs CONCURRENTLY, outside that file's transaction.
--
-- Coverage is NOT equivalent, despite the name similarity: (user_id, created_at)
-- is not a leading-contiguous prefix of (user_id, status, created_at) — `status`
-- sits between them. A query filtering user_id alone and ordering by created_at
-- DESC can use idx_enquiries_user_status_created for the filter but must then
-- Sort, losing LIMIT early-termination. StaffEnquiryController::index does
-- exactly that. Accepted for now (site.enquiries is tiny); if the staff inbox
-- gets slow, the fix is (user_id, created_at DESC) INCLUDE (status).
--
-- Ordered after the CREATEs above so no window exists with neither index.
DROP INDEX CONCURRENTLY IF EXISTS site.enquiries_user_created_idx;
