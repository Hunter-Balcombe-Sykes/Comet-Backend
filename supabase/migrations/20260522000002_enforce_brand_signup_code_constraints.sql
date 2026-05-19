-- Phase A: build the unique index without ACCESS EXCLUSIVE lock on the table.
--
-- NO transaction wrapper for this file.
-- CREATE UNIQUE INDEX CONCURRENTLY cannot run inside a BEGIN/COMMIT block
-- (Postgres raises: "ERROR: CREATE INDEX CONCURRENTLY cannot run inside a
-- transaction block"). Phase B (constraint promotion + NOT NULL) lives in
-- the next migration and IS wrapped in a transaction.
--
-- To revert Phase A: DROP INDEX CONCURRENTLY IF EXISTS brand_profiles_signup_code_unique;

SET lock_timeout = '2s';
SET statement_timeout = '60s';

CREATE UNIQUE INDEX CONCURRENTLY brand_profiles_signup_code_unique
    ON brand.brand_profiles (signup_code);
