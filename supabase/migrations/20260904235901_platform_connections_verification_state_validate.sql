-- Follow-up to 20260903220001 — validates
-- platform_connections_verification_state_check, added NOT VALID there so
-- the guard:no-unsafe-migrations lint would pass (CONVENTIONS.md §2). Kept in
-- its own file rather than folded into the source_intents validate file
-- alongside it: different table, different feature half (verification_state
-- vs. the state/block_reason vocabulary), no shared ordering dependency.
--
-- ROLLBACK: NONE — PostgreSQL has no "un-validate". The real reverse is
--           ALTER TABLE site.platform_connections
--               DROP CONSTRAINT IF EXISTS platform_connections_verification_state_check;
--           (the column itself, verification_state, has no stated reverse in
--           20260903220001 either — it is additive and nullable).

BEGIN;

ALTER TABLE site.platform_connections
    VALIDATE CONSTRAINT platform_connections_verification_state_check;

COMMIT;
