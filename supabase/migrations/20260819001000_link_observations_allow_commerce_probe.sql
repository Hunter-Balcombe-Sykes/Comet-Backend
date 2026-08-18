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

-- Split into the CONVENTIONS.md §2 two-transaction form (2026-08-18). The
-- original single `ADD CONSTRAINT … CHECK` took ACCESS EXCLUSIVE and scanned
-- every partition under the lock — on a month-partitioned observations table
-- that is write downtime. Dev already applied the original and holds an
-- equivalent VALID constraint, so this rewrite changes nothing there; it is
-- prod's eventual apply that it protects.

-- Step A — catalog write only, lock released immediately.
BEGIN;

ALTER TABLE routing.link_observations
    DROP CONSTRAINT IF EXISTS link_observations_source_check;

ALTER TABLE routing.link_observations
    ADD CONSTRAINT link_observations_source_check
    CHECK (source = ANY (ARRAY['paste', 'website_import', 'link_in_bio',
        'bio_harvest', 'google_business', 'staff', 'reproject', 'commerce_probe']))
    NOT VALID;

COMMIT;

-- Step B — separate transaction: SHARE UPDATE EXCLUSIVE, concurrent writes
-- keep flowing. Cannot fail here: the vocabulary only ever widens, so every
-- existing row already satisfies it.
BEGIN;

ALTER TABLE routing.link_observations
    VALIDATE CONSTRAINT link_observations_source_check;

COMMIT;
