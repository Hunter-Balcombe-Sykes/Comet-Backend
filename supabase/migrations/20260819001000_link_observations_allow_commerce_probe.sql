-- 20260819001000_link_observations_allow_commerce_probe.sql
--
-- Overnight 2026-08-18 X3. 20260814100000 admitted 'commerce_probe' as a
-- routing origin on routing.source_intents but never on
-- routing.link_observations.source, so every CommerceProbeJob observation
-- write failed the CHECK (logged as routing.observation.write_failed, no
-- crash — the observation was simply lost). Widen the observations check to
-- the same vocabulary. The table is partitioned by month; ALTER on the parent
-- rewrites the constraint on every partition.
--
-- Rollback: drop and re-add without 'commerce_probe' (fails outright if any
-- 'commerce_probe' row has landed by then — DELETE those first).

BEGIN;

ALTER TABLE routing.link_observations
    DROP CONSTRAINT IF EXISTS link_observations_source_check;

ALTER TABLE routing.link_observations
    ADD CONSTRAINT link_observations_source_check
    CHECK (source = ANY (ARRAY['paste', 'website_import', 'link_in_bio',
        'bio_harvest', 'google_business', 'staff', 'reproject', 'commerce_probe']));

COMMIT;
