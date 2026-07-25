-- Widen site.sites.skeleton_id CHECK to include 'one' (the upcoming ONE skeleton).
-- Reserved value only: no row uses it until V1 ships the renderer + dashboard picker.
-- Widening pattern per supabase/migrations/CONVENTIONS.md §2 (NOT VALID -> VALIDATE);
-- trivial on this table but house-convention. No existing row can violate a superset.
--
-- The two steps need explicit BEGIN/COMMIT windows, not just sequential
-- statements: without them the whole file runs as one implicit transaction,
-- so the ACCESS EXCLUSIVE taken by DROP/ADD CONSTRAINT is held through the
-- VALIDATE scan too, defeating the NOT VALID optimisation (audit MIG-3).
--
-- Down:
--   ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;
--   ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
--     CHECK (skeleton_id IN ('bento','dock','flick','deck','atlas')) NOT VALID;
--   ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;

-- Window 1: swap the CHECK in NOT VALID form. The ACCESS EXCLUSIVE taken for
-- the catalog writes is released at COMMIT, BEFORE the validation scan.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

ALTER TABLE site.sites
  ADD CONSTRAINT sites_skeleton_id_check
  CHECK (skeleton_id IN ('bento', 'dock', 'flick', 'deck', 'atlas', 'one')) NOT VALID;

COMMIT;

-- Window 2: validate in its own transaction. VALIDATE CONSTRAINT takes only
-- SHARE UPDATE EXCLUSIVE, so concurrent reads/writes on site.sites continue.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;

COMMIT;
