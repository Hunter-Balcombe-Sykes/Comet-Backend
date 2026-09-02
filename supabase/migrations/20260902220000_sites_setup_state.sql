-- Setup dialog state (A.9, decision 7). setup_step records the pass being
-- shown so a closed dialog reopens where it left; setup_completed_at is the
-- one "done" bit /me exposes for the open decision. Existing sites are
-- backfilled complete: the dialog is the SIGN-UP onboarding, and springing
-- it on every established account would be noise, not help.
ALTER TABLE "site"."sites" ADD COLUMN "setup_step" text;
ALTER TABLE "site"."sites" ADD COLUMN "setup_completed_at" timestamp with time zone;

UPDATE "site"."sites" SET "setup_completed_at" = now();
