-- guard:no-unsafe-migrations:disable-file
-- Rationale: FK constraints are added without NOT VALID (tables were empty at
-- migration time, pre-beta). The guard opt-out is retained for those FKs.
-- Indexes were moved to sibling file 20260527160001_enquiry_inbox_indexes.sql.
-- ==========================================================================
-- Enquiry Inbox foundation (2026-05-27)
--
-- Adds status enum, customer/notification linkage, status audit timestamps,
-- and a redacted_at column to site.enquiries. Drops the now-redundant
-- enquiries_user_created_idx (its (user_id, created_at DESC) prefix is
-- covered by the new composite (user_id, status, created_at DESC)).
--
-- Indexes (CONCURRENTLY, outside transaction) live in:
--   20260527160001_enquiry_inbox_indexes.sql
--
-- Spec: docs/superpowers/specs/2026-05-26-enquiry-inbox-design.md
-- ==========================================================================

BEGIN;

-- 1. Status enum (idempotent)
DO $$ BEGIN
    CREATE TYPE site.enquiry_status AS ENUM ('new', 'read', 'replied', 'archived', 'spam');
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

-- 2. Status column with backfill-friendly default
ALTER TABLE site.enquiries
    ADD COLUMN IF NOT EXISTS status site.enquiry_status NOT NULL DEFAULT 'new';

-- 3. Backfill status from existing read_at (only untouched rows)
UPDATE site.enquiries SET status = 'read' WHERE read_at IS NOT NULL AND status = 'new';

-- 4. New FK + timestamp columns (all nullable, no rewrite)
ALTER TABLE site.enquiries
    ADD COLUMN IF NOT EXISTS customer_id uuid,
    ADD COLUMN IF NOT EXISTS notification_id uuid,
    ADD COLUMN IF NOT EXISTS replied_at timestamptz,
    ADD COLUMN IF NOT EXISTS archived_at timestamptz,
    ADD COLUMN IF NOT EXISTS spam_at timestamptz,
    ADD COLUMN IF NOT EXISTS redacted_at timestamptz;

-- 4b. Add redacted_at to site.customers (PII redaction cascade target — Task 7)
ALTER TABLE site.customers ADD COLUMN IF NOT EXISTS redacted_at timestamptz;

-- 5. Backfill customer_id by email match (live customers only, unlinked rows only)
UPDATE site.enquiries e
SET customer_id = c.id
FROM site.customers c
WHERE c.user_id = e.user_id
  AND lower(c.email) = lower(e.email)
  AND c.deleted_at IS NULL
  AND e.customer_id IS NULL;

-- 6. FK constraints (idempotent — ADD CONSTRAINT has no IF NOT EXISTS)
DO $$ BEGIN
    ALTER TABLE site.enquiries
        ADD CONSTRAINT enquiries_customer_fk
            FOREIGN KEY (customer_id) REFERENCES site.customers(id) ON DELETE SET NULL;
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

DO $$ BEGIN
    ALTER TABLE site.enquiries
        ADD CONSTRAINT enquiries_notification_fk
            FOREIGN KEY (notification_id) REFERENCES notifications.notifications(id) ON DELETE SET NULL;
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

-- 7. Indexes moved to sibling file 20260527160001_enquiry_inbox_indexes.sql.
--    CREATE INDEX CONCURRENTLY cannot run inside a transaction; the sibling runs
--    immediately after this file on a fresh build (CONVENTIONS.md §1).

-- 8. Drop the redundant index (covered by the new composite).
DROP INDEX IF EXISTS site.enquiries_user_created_idx;

COMMIT;
