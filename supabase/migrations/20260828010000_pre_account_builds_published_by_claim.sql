-- T28 (issue 22, 2026-08-27 post-claim round): release() must restore the
-- pre-claim publish state, and "was this site published BY the claim?" is a
-- fact only claim() knows at flip time. The old heuristic (! isOutreach())
-- assumed outreach builds are provisioned published — but publish intent is
-- a requestBuild() parameter that only rides the job dispatch, so a staff/
-- outreach build CAN be provisioned unpublished (the whole 2026-08-27 test
-- fleet is), and releasing a claim on one left is_published=true on an
-- unclaimed row: more exposed than before the claim, owned by nobody.
--
-- claim() sets this flag in the same forceFill as claimed_at when it flips
-- is_published; release() unpublishes exactly when it is true, then clears
-- it. DEFAULT false backfills every existing row correctly: no historical
-- claim needs restoring (released rows were already handled or repaired).
BEGIN;

ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS published_by_claim boolean NOT NULL DEFAULT false;

COMMIT;
