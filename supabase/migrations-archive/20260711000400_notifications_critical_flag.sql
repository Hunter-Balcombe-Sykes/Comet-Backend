-- 20260711000400_notifications_critical_flag.sql
--
-- OV-A: routing flag for the notification email path (OV-H). `critical` marks
-- a notification for in-app + email delivery; non-critical stays in-app only
-- (with ends_at auto-cleanup). Deliberately separate from `severity` — severity
-- is DISPLAY styling (a 'Warning'-styled notice can still be routine), critical
-- is the DELIVERY escalation switch read by the email dispatcher.
--
-- ADD COLUMN with a constant default is metadata-only on PG11+ — no table
-- rewrite, no long lock. Down: ALTER TABLE notifications.notifications DROP COLUMN critical;

ALTER TABLE notifications.notifications
    ADD COLUMN IF NOT EXISTS critical boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN notifications.notifications.critical IS
  'Delivery escalation: true → in-app + email (dispatcher path, OV-H); false → in-app only. Independent of display severity.';
