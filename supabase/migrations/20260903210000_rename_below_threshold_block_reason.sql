-- Retire the last piece of the confidence system's vocabulary (owner,
-- 2026-09-03: "ensure we delete all legacy as we do this also").
--
-- `block_reason = 'below_threshold'` named a threshold that no longer exists.
-- It was minted whenever a decision came out as "ask, don't apply", and after
-- the thresholds were deleted the ONE writer left is StoreBrandSeeder's
-- suggest-only downgrade: a probe that resolved a store on a link the person
-- pasted, offered as a question instead of installed. That is not a link
-- falling short of a number — it is a link waiting for a yes, which is what
-- the new name says.
--
-- Reachability, checked rather than assumed before touching this: the
-- downgrade fires only when a Placement arrives as Place AND the probe is
-- suggest-only, and CommerceProbeJob:323-324 produces exactly that pair on the
-- accept lane for a deep store path (suggestOnly = deepPage, confirmed =
-- acceptedIntentId). Live code, not a leftover — renamed, not deleted.
--
-- Ordering: widen the CHECK, move the rows, then narrow it. Doing the UPDATE
-- under the old constraint would fail on the new value, and dropping straight
-- to the new list would fail on the old rows.
--
-- NOT VALID added 2026-09-04 (guard:no-unsafe-migrations Check 3 / CONVENTIONS.md
-- §2) — the ADD below is not paired with its own VALIDATE here because
-- 20260903220001 DROPs and re-ADDs this same constraint downstream (widening it
-- further with 'not_found'), so the object this NOT VALID attaches to is
-- superseded before anything would validate it. The live constraint's VALIDATE
-- lives in 20260904235900_source_intents_state_block_reason_validate.sql,
-- timestamped after 20260903220001 so it validates the FINAL definition.
--
-- `sibling_branch` belongs to ANOTHER lane (its migration is already applied on
-- dev) and is carried through untouched. It is not ours to retire, and a list
-- that omitted it would drop a value that lane's writer still mints. Verified
-- against dev's live constraint before writing this, not assumed.
--
-- ROLLBACK: ALTER TABLE "routing"."source_intents" DROP CONSTRAINT IF EXISTS "source_intents_block_reason_check";
--           UPDATE "routing"."source_intents" SET "block_reason" = 'below_threshold'
--             WHERE "block_reason" = 'needs_confirmation';
--           then re-add the constraint with the PREVIOUS vocabulary — this
--           file's list with 'needs_confirmation' and 'not_found' removed and
--           'below_threshold' put back. Stated as a delta rather than copied
--           out (CONVENTIONS.md §10, "do not reproduce a vocabulary IN (...)
--           list inside a note"); a full reverse also has to undo
--           20260903220001, which re-declares the same constraint downstream.
--           (Exact, not lossy: this file is the only writer of
--           'needs_confirmation', so the reverse UPDATE moves back precisely
--           the rows the forward one moved — unless a later intent is written
--           between the two, which would be re-labelled as though it had been
--           one of the originals.)

ALTER TABLE "routing"."source_intents" DROP CONSTRAINT IF EXISTS "source_intents_block_reason_check";

UPDATE "routing"."source_intents"
   SET "block_reason" = 'needs_confirmation'
 WHERE "block_reason" = 'below_threshold';

ALTER TABLE "routing"."source_intents"
  ADD CONSTRAINT "source_intents_block_reason_check"
  CHECK ("block_reason" IS NULL OR "block_reason" IN (
      'gate', 'capability', 'conflict', 'cap_reached', 'needs_confirmation',
      'tombstoned', 'unservable', 'invalid_identifier', 'duplicate', 'not_found',
      'sibling_branch'
  )) NOT VALID;
