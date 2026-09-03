-- ROLLBACK:
--   UPDATE routing.source_intents SET state = 'proposed' WHERE state = 'verifying';
--   UPDATE routing.source_intents SET block_reason = 'unservable' WHERE block_reason = 'not_found';
--   ALTER TABLE routing.source_intents DROP CONSTRAINT IF EXISTS source_intents_state_check;
--   ALTER TABLE routing.source_intents ADD CONSTRAINT source_intents_state_check
--       CHECK (state IN ('proposed','applied','blocked','dismissed','superseded'));
--   -- then re-create the three indexes below without 'verifying'.
-- The two UPDATEs must run BEFORE the CHECK is narrowed or it will not validate.
-- Rolling back loses only which rows were mid-verification; the next harvest
-- re-proposes them.

-- ── Why a fourth live state ─────────────────────────────────────────────────
--
-- A suggestion could only be 'proposed' (waiting for the person) or 'applied'
-- (connected). There was nowhere for one to wait on US.
--
-- Since 2026-09-03 an accept on a link whose detector matched nothing but the
-- brand's domain (L1 WEAK — see App\Routing\LinkValidity) has to check that the
-- page actually exists before it will claim the page is the person's account.
-- That check is a network call, and LinkProbeWorker forbids network work in a
-- request cycle, so accept has to hand off to a queue and answer "we're
-- checking" — which is a state, not a flag.
--
-- 'verifying' is LIVE: it holds the (user, surface, identifier) slot exactly as
-- 'proposed' does, so a second harvest of the same link cannot open a duplicate
-- while the first is still being checked. It is also STUCK-VISIBLE: a job that
-- dies must show up in the staff stuck-intents feed rather than leaving a
-- suggestion the person can never answer.
--
-- It always terminates. The verifier resolves to exactly one of:
--   found     → 'applied' (connection written, verification_state 'verified')
--   not_found → 'blocked' + block_reason 'not_found' (nothing written)
--   blocked   → 'applied' (connection written, verification_state 'unverified')
-- and a job that never lands at all times out to the LAST of those. Being
-- unable to check is never treated as a failure of the link — a brand that
-- blocks our fetch has told us nothing about whether the page is real.
DO $$
BEGIN
    ALTER TABLE routing.source_intents DROP CONSTRAINT IF EXISTS source_intents_state_check;

    ALTER TABLE routing.source_intents
        ADD CONSTRAINT source_intents_state_check
        CHECK (state IN ('proposed', 'verifying', 'applied', 'blocked', 'dismissed', 'superseded'));
END $$;

-- 'not_found' joins the block_reason vocabulary: the page the link names does
-- not exist. Deliberately NOT folded into 'unservable', which means "this build
-- cannot serve that surface" — a fact about us, where this is a fact about the
-- link, and the inbox says different words for each.
DO $$
BEGIN
    ALTER TABLE routing.source_intents DROP CONSTRAINT IF EXISTS source_intents_block_reason_check;

    ALTER TABLE routing.source_intents
        ADD CONSTRAINT source_intents_block_reason_check
        CHECK (block_reason IS NULL OR block_reason IN (
            'gate', 'capability', 'conflict', 'cap_reached', 'needs_confirmation',
            'tombstoned', 'unservable', 'invalid_identifier', 'duplicate',
            'not_found', 'sibling_branch'
        ));
END $$;

-- The three partial indexes all enumerate the live states by hand, so each has
-- to learn the new one or it stops covering rows that are very much live.
DROP INDEX IF EXISTS routing.idx_source_intents_live;
CREATE UNIQUE INDEX idx_source_intents_live
    ON routing.source_intents (user_id, surface_key, identifier)
    WHERE (state IN ('proposed', 'verifying', 'applied', 'blocked'));

DROP INDEX IF EXISTS routing.idx_source_intents_stuck;
CREATE INDEX idx_source_intents_stuck
    ON routing.source_intents (state, first_seen_at)
    WHERE (state IN ('proposed', 'verifying', 'blocked'));

COMMENT ON COLUMN routing.source_intents.state IS
    'proposed = waiting on the person; verifying = accepted, waiting on our own L2 check of the link; applied = connected; blocked = held with a block_reason; dismissed/superseded = settled, slot free.';

-- ── The other half: was the connection's link ever actually checked ─────────
--
-- A separate column rather than a payload key, for the same reason owner_scope
-- got one: this drives whether the dashboard shows an "unverified" marker, and
-- a value that fails to write must be VISIBLY null rather than silently absent
-- from a JSON blob.
--
-- NULL means the question was never asked — every row predating 2026-09-03, and
-- every connection whose identity the person GAVE us (a Google Business
-- place_id, the sign-up Instagram handle) rather than one read off a URL.
ALTER TABLE site.platform_connections
    ADD COLUMN IF NOT EXISTS verification_state TEXT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'site.platform_connections'::regclass
          AND conname = 'platform_connections_verification_state_check'
    ) THEN
        ALTER TABLE site.platform_connections
            ADD CONSTRAINT platform_connections_verification_state_check
            CHECK (verification_state IS NULL OR verification_state IN ('verified', 'unverified'));
    END IF;
END $$;

COMMENT ON COLUMN site.platform_connections.verification_state IS
    '''verified'' = we fetched the page and it exists. ''unverified'' = we could not check (the brand blocks us, or has no mechanism) and saved it anyway — never a claim that the link is wrong. NULL = never asked.';
