-- Widen site.sites.skeleton_id CHECK to include 'one' (the upcoming ONE skeleton).
-- Reserved value only: no row uses it until V1 ships the renderer + dashboard picker.
-- Widening pattern per supabase/migrations/CONVENTIONS.md §2 (NOT VALID -> VALIDATE);
-- trivial on this table but house-convention. No existing row can violate a superset.
--
-- Down:
--   ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;
--   ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
--     CHECK (skeleton_id IN ('bento','dock','flick','deck','atlas')) NOT VALID;
--   ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

ALTER TABLE site.sites
  ADD CONSTRAINT sites_skeleton_id_check
  CHECK (skeleton_id IN ('bento', 'dock', 'flick', 'deck', 'atlas', 'one')) NOT VALID;

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;
