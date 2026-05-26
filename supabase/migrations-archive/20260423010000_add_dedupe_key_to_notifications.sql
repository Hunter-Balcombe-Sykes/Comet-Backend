--
-- Replaces URL-based dedup (CTA URL with ?notif=key appended) with a proper
-- dedupe_key column. Partial unique index so NULL dedupe_keys don't collide.
-- Emitters that want dedup pass a `dedupeKey` to NotificationPublisher::publish();
-- the publisher writes it to this column and relies on ON CONFLICT DO NOTHING
-- for atomic idempotency.

BEGIN;

ALTER TABLE notifications.notifications
    ADD COLUMN IF NOT EXISTS dedupe_key text;

CREATE UNIQUE INDEX IF NOT EXISTS notifications_dedupe_key_per_pro_uq
    ON notifications.notifications (professional_id, dedupe_key)
    WHERE dedupe_key IS NOT NULL;

COMMIT;
