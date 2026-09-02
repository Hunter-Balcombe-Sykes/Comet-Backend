-- ROLLBACK: NONE. Nulling setup_completed_at back out would also clear rows
-- the setup dialog has legitimately stamped since this backfill ran.
-- Backfill for 20260902220000: existing sites are marked setup-complete —
-- the dialog is the SIGN-UP onboarding, and springing it on every
-- established account would be noise, not help. Runs outside the DDL
-- transaction (CONVENTIONS.md §5) with a session-level lock timeout.
SET lock_timeout = '2s';

UPDATE "site"."sites" SET "setup_completed_at" = now() WHERE "setup_completed_at" IS NULL;
