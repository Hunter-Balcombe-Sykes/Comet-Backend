-- ==========================================================================
-- Enquiry Inbox foundation (2026-05-27)
--
-- Adds status enum, customer/notification linkage, status audit timestamps,
-- and a redacted_at column to site.enquiries. Drops the now-redundant
-- enquiries_user_created_idx (its (user_id, created_at DESC) prefix is
-- covered by the new composite (user_id, status, created_at DESC)).
--
-- Spec: docs/superpowers/specs/2026-05-26-enquiry-inbox-design.md
-- ==========================================================================

BEGIN;

-- 1. Status enum
CREATE TYPE site.enquiry_status AS ENUM ('new', 'read', 'replied', 'archived', 'spam');

-- 2. Status column with backfill-friendly default
ALTER TABLE site.enquiries
    ADD COLUMN status site.enquiry_status NOT NULL DEFAULT 'new';

-- 3. Backfill status from existing read_at
UPDATE site.enquiries SET status = 'read' WHERE read_at IS NOT NULL;

-- 4. New FK + timestamp columns (all nullable, no rewrite)
ALTER TABLE site.enquiries
    ADD COLUMN customer_id uuid,
    ADD COLUMN notification_id uuid,
    ADD COLUMN replied_at timestamptz,
    ADD COLUMN archived_at timestamptz,
    ADD COLUMN spam_at timestamptz,
    ADD COLUMN redacted_at timestamptz;

-- 4b. Add redacted_at to site.customers (PII redaction cascade target — Task 7)
ALTER TABLE site.customers ADD COLUMN IF NOT EXISTS redacted_at timestamptz;

-- 5. Backfill customer_id by email match (live customers only)
UPDATE site.enquiries e
SET customer_id = c.id
FROM site.customers c
WHERE c.user_id = e.user_id
  AND lower(c.email) = lower(e.email)
  AND c.deleted_at IS NULL;

-- 6. FK constraints
ALTER TABLE site.enquiries
    ADD CONSTRAINT enquiries_customer_fk
        FOREIGN KEY (customer_id) REFERENCES site.customers(id) ON DELETE SET NULL,
    ADD CONSTRAINT enquiries_notification_fk
        FOREIGN KEY (notification_id) REFERENCES notifications.notifications(id) ON DELETE SET NULL;

COMMIT;

-- CONCURRENTLY index creation MUST live outside any transaction block.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_user_status_created
    ON site.enquiries (user_id, status, created_at DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_customer
    ON site.enquiries (customer_id)
    WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_enquiries_notification
    ON site.enquiries (notification_id)
    WHERE notification_id IS NOT NULL;

-- Drop the redundant index (covered by the new composite).
DROP INDEX CONCURRENTLY IF EXISTS site.enquiries_user_created_idx;
