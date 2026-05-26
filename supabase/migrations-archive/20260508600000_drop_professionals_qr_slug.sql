-- 20260508600000_drop_professionals_qr_slug.sql
-- Drops the qr_slug column from core.professionals.
-- All PHP code references were removed before this migration runs.
-- NOTE: notifications.email_subscriptions.qr_slug is a separate per-subscription
-- tracking token (different purpose, different table) — left untouched.

BEGIN;

ALTER TABLE core.professionals DROP COLUMN IF EXISTS qr_slug;

COMMIT;
