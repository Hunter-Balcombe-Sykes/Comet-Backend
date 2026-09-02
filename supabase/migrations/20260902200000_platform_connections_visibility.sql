-- Hidden connections (setup-dialog run A.3, decision 4). A sign-up build's
-- pre-scrape connects platforms invisibly: ingest runs so the setup dialog
-- has real items to offer, but the row is excluded from the public payload,
-- the connection list, caps, page presence, the inbox connected-filter and
-- the pool publish rules until the person accepts it (reveal) or dismisses
-- it (discard). `is_active` is untouched — active/hidden are different axes.
BEGIN;
ALTER TABLE "site"."platform_connections"
    ADD COLUMN "visibility" text NOT NULL DEFAULT 'visible';

ALTER TABLE "site"."platform_connections"
    ADD CONSTRAINT platform_connections_visibility_check
    CHECK ("visibility" IN ('visible', 'hidden')) NOT VALID;
COMMIT;

-- Validate outside the ADD's transaction (CONVENTIONS.md §2 / guard check 8).
BEGIN;
ALTER TABLE "site"."platform_connections" VALIDATE CONSTRAINT platform_connections_visibility_check;
COMMIT;
