-- ROLLBACK:
--   BEGIN;
--   ALTER TABLE routing.source_intents DROP CONSTRAINT IF EXISTS source_intents_block_reason_check;
--   ALTER TABLE routing.source_intents ADD CONSTRAINT source_intents_block_reason_check
--       CHECK ("block_reason" IS NULL OR "block_reason" IN (
--           'gate', 'capability', 'conflict', 'cap_reached', 'below_threshold',
--           'tombstoned', 'unservable', 'invalid_identifier', 'duplicate')) NOT VALID;
--   COMMIT;
--   BEGIN;
--   ALTER TABLE routing.source_intents VALIDATE CONSTRAINT source_intents_block_reason_check;
--   COMMIT;
-- A widening, so the inverse is the narrower list — but its VALIDATE FAILS while
-- any row still carries 'sibling_branch'. Settle those first:
--   UPDATE routing.source_intents SET block_reason = 'conflict'
--    WHERE block_reason = 'sibling_branch';
-- which restores the pre-change behaviour exactly (those rows go back to being
-- rendered as Swap offers), losing only the record that they were siblings.

-- A partna's workplace website is often a CHAIN's site, whose locations page
-- carries one booking link per branch. Fresha models every branch as its own
-- business — separate venue id, separate owner id, empty `additionalLocations`
-- (verified live against three The Barber Club branches, 2026-09-03) — so
-- ConnectionIdentity correctly declines to merge them and the booking XOR
-- holds each one after the first. Six branches became five "You already have a
-- booking link. Use this Fresha one instead?" cards; teegandyson and
-- liamsaunders carried exactly five each on dev.
--
-- 'sibling_branch' is that verdict: recognised, recorded, and NOT a question —
-- the slot was never in dispute, because the incumbent is either the account
-- holder's own link or a branch whose roster already named them. It is a
-- blocked state like its neighbours, so the row stays inside
-- idx_source_intents_live and a re-harvest advances it rather than stacking a
-- duplicate. See SourceReconciler::isSettledWorkplaceSlot().
--
-- Two windows per CONVENTIONS.md §2. The DROP is a catalog write only, and the
-- new list is a strict SUPERSET of the old, so every existing row already
-- satisfies it and the validation scan below cannot fail on current data.

-- Step A — swap the constraint for the widened one, lock-light (NOT VALID
-- skips the scan, so ACCESS EXCLUSIVE is released at COMMIT rather than held
-- through a full-table read).
BEGIN;

ALTER TABLE routing.source_intents
    DROP CONSTRAINT IF EXISTS source_intents_block_reason_check;

ALTER TABLE routing.source_intents
    ADD CONSTRAINT source_intents_block_reason_check
    CHECK ("block_reason" IS NULL OR "block_reason" IN (
        'gate', 'capability', 'conflict', 'cap_reached', 'below_threshold',
        'tombstoned', 'unservable', 'invalid_identifier', 'duplicate',
        'sibling_branch'
    )) NOT VALID;

COMMIT;

-- Step B — validate in its own transaction (SHARE UPDATE EXCLUSIVE, so reads
-- and writes continue). Separate window on purpose: bundling it above would
-- hold the heavier lock through the scan and defeat the split (guard Check 8).
BEGIN;

ALTER TABLE routing.source_intents
    VALIDATE CONSTRAINT source_intents_block_reason_check;

COMMIT;
