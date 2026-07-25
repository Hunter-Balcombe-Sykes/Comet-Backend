-- 20260606010000_add_deletion_mail_sent_at.sql
--
-- Adds deletion_mail_sent_at to core.users to guard against double-delivery of
-- the account-deletion confirmation email on job retries (SendAccountDeletionRequestMailJob).
-- NULL means the mail has not been sent yet; non-null means it was stamped atomically
-- under a SELECT ... FOR UPDATE lock and the mail should not be re-sent.
--
-- Plain nullable ADD COLUMN — no default, no NOT NULL, no index required.
-- Safe on a populated table (no lock contention, no row scan).

ALTER TABLE core.users
    ADD COLUMN IF NOT EXISTS deletion_mail_sent_at TIMESTAMPTZ;
