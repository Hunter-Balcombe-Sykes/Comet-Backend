-- Add moderation_state to site.sites. NOT NULL DEFAULT 'active' applies the
-- default to existing rows in one shot — pre-beta means no production data
-- is at risk; CONVENTIONS.md §3 four-step pattern is the alternative if/when
-- this becomes a populated prod migration.

BEGIN;

ALTER TABLE site.sites
    ADD COLUMN IF NOT EXISTS moderation_state VARCHAR(20) NOT NULL DEFAULT 'active';

ALTER TABLE site.sites
    ADD CONSTRAINT sites_moderation_state_check
    CHECK (moderation_state IN ('active', 'warned', 'hidden')) NOT VALID;

COMMIT;

-- Validate in a second transaction (separate locks; CONVENTIONS.md §2).
BEGIN;
ALTER TABLE site.sites VALIDATE CONSTRAINT sites_moderation_state_check;
COMMIT;
