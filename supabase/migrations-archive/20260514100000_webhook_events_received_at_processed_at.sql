-- Webhook event semantics: separate "received" (dedup row created) from "processed"
-- (handler ran to completion).
--
-- Pre-fix: processed_at was set on row creation inside firstOrCreate(), which meant
-- "received", not "processed". Combined with the new STRP-C delete-on-failure pattern
-- in ValidatesStripeWebhookPayload::runHandlerWithFailureCleanup, the cleaner shape is:
--
--   received_at  — set on dedup row creation (always populated for surviving rows)
--   processed_at — set after the handler completes successfully (NULL until then)
--
-- Existing rows: their old processed_at value semantically matched "received" (creation
-- time), so we preserve it by RENAMING the column. The new processed_at column starts
-- NULL for legacy rows — that's correct; we didn't track post-handler completion before.

ALTER TABLE billing.webhook_events RENAME COLUMN processed_at TO received_at;

ALTER TABLE billing.webhook_events ADD COLUMN processed_at TIMESTAMPTZ NULL DEFAULT NULL;

COMMENT ON COLUMN billing.webhook_events.received_at IS
    'Set when the dedup row is created (event accepted for processing). Was named processed_at pre-v2 fixes.';

COMMENT ON COLUMN billing.webhook_events.processed_at IS
    'Set after the handler completes successfully. NULL = received but handler did not finish. '
    'Combined with the STRP-C delete-on-failure pattern (trait deletes the row on handler exception '
    'so Stripe can retry), persistent NULLs indicate stuck or unprocessable events that survived a '
    'delete-on-failure attempt.';

-- Supports reconciliation queries that find events received-but-not-processed.
CREATE INDEX IF NOT EXISTS idx_webhook_events_received_unprocessed
    ON billing.webhook_events (received_at)
    WHERE processed_at IS NULL;
