-- Add email_sent_at to core.data_export_audits to prevent duplicate GDPR export
-- emails on job crash-retry. The job stamps this column immediately after
-- Mail::send() succeeds; on retry the guard checks for a non-null value
-- and skips re-sending. At-least-once: a crash between send and stamp causes
-- one duplicate — acceptable for GDPR exports (better than silent loss).

ALTER TABLE core.data_export_audit
    ADD COLUMN IF NOT EXISTS email_sent_at TIMESTAMPTZ NULL;
