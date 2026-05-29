-- =====================================================================
-- Visitor confirmation idempotency stamp
-- =====================================================================
-- Adds confirmation_sent_at to the two public-submission source tables.
-- Set once the visitor-facing confirmation email is delivered, so job
-- retries / Horizon scale-out never double-send. Separate from
-- site.enquiries.email_sent_at, which tracks the PROFESSIONAL's inbox
-- notification (a different recipient + concern).
--
-- On a genuine re-subscribe (unsubscribed -> subscribed) the column is
-- reset to NULL in PublicEmailSubscriptionController so a real opt-in
-- re-confirms. NULLABLE, no DB default.
-- =====================================================================

BEGIN;

ALTER TABLE site.enquiries
    ADD COLUMN IF NOT EXISTS confirmation_sent_at timestamptz NULL;

ALTER TABLE notifications.email_subscriptions
    ADD COLUMN IF NOT EXISTS confirmation_sent_at timestamptz NULL;

COMMIT;
