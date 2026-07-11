-- 20260711170000_users_email_unique_case_insensitive.sql
--
-- LIFE-6 (user-deletion-lifecycle audit): UserBootstrapService's email-reuse guard
-- (guardAgainstEmailReuseByDifferentAuthUser) compares lower(primary_email), but the
-- unique index enforced case-sensitive primary_email — so 'Foo@bar.com' and
-- 'foo@bar.com' could both insert, defeating the guard the app assumes the DB backs.
-- Re-create the uniqueness index on lower(primary_email) so the database enforces
-- exactly what the application checks.
--
-- Verified 0 case-insensitive duplicate emails among non-deleted users on the dev
-- database before writing this — no dedupe step is required.
--
-- No BEGIN/COMMIT — CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction
-- (CONVENTIONS §1). Build-new-then-swap keeps a uniqueness guarantee in force the
-- whole time: the new case-insensitive index is strictly stronger than the old
-- case-sensitive one, so there is never a window where a duplicate could slip in.

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS users_email_unique_ci
    ON core.users (lower(primary_email)) WHERE (deleted_at IS NULL);

DROP INDEX CONCURRENTLY IF EXISTS core.users_email_unique;

ALTER INDEX core.users_email_unique_ci RENAME TO users_email_unique;
