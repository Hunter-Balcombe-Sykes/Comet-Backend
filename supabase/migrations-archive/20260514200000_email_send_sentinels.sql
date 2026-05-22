-- Master Pattern 21: Email-send idempotency sentinels
-- Adds email_sent_at stamps to three tables so each email-send job can
-- check before sending and stamp after, preventing duplicate sends on retry.

ALTER TABLE notifications.notifications
    ADD COLUMN IF NOT EXISTS email_sent_at timestamptz;

ALTER TABLE site.enquiries
    ADD COLUMN IF NOT EXISTS email_sent_at timestamptz;

-- Separate receipt table for broadcast emails because the source row
-- (notifications.notifications) is shared across all recipients — it
-- cannot carry per-subscription state. The PK constraint is the dedup guard.
CREATE TABLE IF NOT EXISTS notifications.broadcast_email_receipts (
    notification_id uuid NOT NULL REFERENCES notifications.notifications(id) ON DELETE CASCADE,
    subscription_id uuid NOT NULL,
    email_sent_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (notification_id, subscription_id)
);
