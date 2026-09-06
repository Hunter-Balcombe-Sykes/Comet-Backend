-- ROLLBACK:
--   DELETE FROM site.workplace_candidates WHERE source = 'previously_connected';
--   ALTER TABLE site.workplace_candidates DROP CONSTRAINT IF EXISTS workplace_candidates_source_check;
--   ALTER TABLE site.workplace_candidates ADD CONSTRAINT workplace_candidates_source_check
--       CHECK (source IN ('bio_mention', 'fresha'));
--   -- (20260906090001's VALIDATE has no separate undo — dropping the constraint here removes it too.)
--
-- GoogleBusinessController::connect() writes source = 'previously_connected'
-- whenever a picker swap displaces an existing Google Business listing (the
-- row lets the person get the old business back via the listing pass — see
-- that method's own PWL-1/"Select your workplace" comment, 2026-09-05). The
-- CHECK constraint here was never widened to admit it, so every real picker
-- swap has been hitting a CHECK-constraint violation (SQLSTATE 23514) since
-- that write landed — withConnectionLock only catches a lock timeout, so the
-- violation propagates straight out of connect() as an uncaught 500 instead
-- of the swap succeeding quietly. Found migrating that same block off raw
-- payload-array reads onto GoogleBusinessPayload, 2026-09-06.
DO $$
BEGIN
    ALTER TABLE site.workplace_candidates DROP CONSTRAINT IF EXISTS workplace_candidates_source_check;

    ALTER TABLE site.workplace_candidates
        ADD CONSTRAINT workplace_candidates_source_check
        CHECK (source IN ('bio_mention', 'fresha', 'previously_connected')) NOT VALID;
END $$;
