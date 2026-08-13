-- Slice 6 Task 1. content.f_review carries the reviewer's display name and
-- photo but not the URI of their Google contributor profile, so the content.*
-- lane could not reach parity with the legacy platform_connections payload
-- (GoogleBusinessService::mapDetails' `authorUri`). Unit 3 retires that legacy
-- read; without this column the retirement would silently DROP a field that
-- docs/legal/reviewer-data-disclosure.md §1 lists as published today, which is
-- a change to what third-party data reaches the public wire — a legal-review
-- item, not a refactor.
--
-- Inherits redaction with no manifest change: GoogleBusinessConnector's
-- Manifest::$redactionScopes already declares `author_uri` as when_unclaimed
-- alongside `author` and `author_photo`, so an unclaimed owner's record lands
-- with it stripped exactly as the other two already do.
--
-- No CONCURRENTLY: a nullable ADD COLUMN with no DEFAULT and no index is a
-- catalog-only metadata change on Postgres 11+ — no table rewrite, no scan.
-- A future change to this table that DOES scan or rewrite needs its own
-- treatment and must not inherit this justification.
--
-- NOT APPLIED by this task — no supabase db push, no db link, no MCP database
-- call was run. The coordinator applies it to the shared dev database (two
-- other sessions use it). The SQLite stand-in and Postgres-lane provisioning
-- carry it so the suites run either way.
--
-- ROLLBACK: ALTER TABLE content.f_review DROP COLUMN IF EXISTS author_uri;

BEGIN;

ALTER TABLE content.f_review
    ADD COLUMN IF NOT EXISTS author_uri text NULL;

COMMENT ON COLUMN content.f_review.author_uri IS
    'Permanent link to the reviewer''s Google contributor profile (authorAttribution.uri). Third-party PII: stripped before landing for unclaimed owners via Manifest::$redactionScopes when_unclaimed, hard-deleted by content:prune-orphaned-review-pii, and omitted from DSAR exports alongside author_name/author_photo_url/text.';

COMMIT;
