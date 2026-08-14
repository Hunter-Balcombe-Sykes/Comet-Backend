-- routing.source_intents: allow origin 'commerce_probe'.
--
-- CommerceProbeJob::ORIGIN has always been 'commerce_probe', but the origin
-- CHECK shipped in 20260727120000_routing_schema.sql never listed it, so every
-- probe that resolved a store threw 23514 at SourceReconciler's intent insert.
-- The probe itself succeeded — only the routing history failed to record, which
-- is why it presented as missing suggestions rather than as a broken probe.
--
-- The constraint is declared inline (unnamed) in the original CREATE TABLE, so
-- Postgres auto-named it; the name below was read off dev's pg_constraint
-- rather than assumed.
--
-- This WIDENS the domain, so the existing-row scan cannot fail — every current
-- row already satisfies a superset. It still takes the mandatory two-window
-- shape (§2): NOT VALID here, VALIDATE in a separate transaction. The
-- guard is mechanical by design, and "this particular ALTER is provably safe"
-- is exactly the argument that stops being true when someone copies the file.
--
-- DROP ... IF EXISTS so a re-apply after ledger drift is a no-op rather than a
-- hard failure (the MCP stamps its own version, so db push may re-run this).
--
-- ROLLBACK: BEGIN;
--             ALTER TABLE routing.source_intents DROP CONSTRAINT IF EXISTS source_intents_origin_check;
--             ALTER TABLE routing.source_intents ADD CONSTRAINT source_intents_origin_check
--               CHECK (origin = ANY (ARRAY['paste','website_import','link_in_bio',
--                 'bio_harvest','google_business','staff','reproject'])) NOT VALID;
--           COMMIT;
--           BEGIN; ALTER TABLE routing.source_intents VALIDATE CONSTRAINT source_intents_origin_check; COMMIT;
--           (Reverting re-breaks CommerceProbeJob, and the VALIDATE fails
--            outright if any 'commerce_probe' row has landed by then — DELETE
--            those first.)

BEGIN;

ALTER TABLE routing.source_intents
    DROP CONSTRAINT IF EXISTS source_intents_origin_check;

ALTER TABLE routing.source_intents
    ADD CONSTRAINT source_intents_origin_check
    CHECK (origin = ANY (ARRAY['paste', 'website_import', 'link_in_bio',
        'bio_harvest', 'google_business', 'staff', 'reproject', 'commerce_probe'])) NOT VALID;

COMMIT;

BEGIN;

ALTER TABLE routing.source_intents VALIDATE CONSTRAINT source_intents_origin_check;

COMMIT;
