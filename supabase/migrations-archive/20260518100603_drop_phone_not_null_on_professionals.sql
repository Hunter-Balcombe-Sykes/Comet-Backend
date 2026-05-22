-- Drop NOT NULL constraint on core.professionals.phone so OAuth sign-ups
-- (Google, Apple, etc.) — which never carry a phone number — can complete
-- bootstrap. Validation in BootstrapRequest already treats phone as
-- nullable; this aligns the database schema with the application contract.
--
-- Safe operation: ALTER COLUMN DROP NOT NULL is a metadata-only change in
-- PostgreSQL — no row rewrite, no row scan. Acquires ACCESS EXCLUSIVE
-- briefly but releases immediately.

BEGIN;

ALTER TABLE core.professionals
    ALTER COLUMN phone DROP NOT NULL;

COMMIT;
