-- 20260712060000_backfill_staff_account_type.sql
--
-- OV-A follow-up (two-part fix). 20260711000000 added the 'staff' account type
-- — enum + CHECK constraint widening + capabilities + frontend detection — but
-- on the dev DB (which also serves prod) it landed only PARTIALLY:
--
--   1. The CHECK constraint was NEVER widened. Live definition on dev is still
--      CHECK (account_type IN ('partna','business')) — verified 2026-07-12
--      against pg_constraint, so any attempt to store 'staff' 23514-errors.
--      (The migration-history table is unreliable here: only 20260711170000 of
--      the 18 July 10-11 migrations is *recorded* as applied, yet most of their
--      schema changes — architecture_id, design_kits, the partna_staff table
--      itself — are demonstrably live. The schema is the source of truth, not
--      supabase_migrations.schema_migrations, and the schema shows the widening
--      never happened.)
--   2. No staff member's core.users row was ever flipped to account_type='staff'.
--      All three core.partna_staff members sit at account_type='partna', so the
--      dashboard renders them as a normal professional instead of the staff
--      surface (which keys off account_type='staff' via AccountCapabilities::
--      isStaff + the frontend /me path).
--
-- This migration fixes both, idempotently. It grants NO new privilege: the real
-- staff authorization boundary is the partna_staff row (enforced by
-- EnsurePartnaStaff + the staff policies via PartnaStaff::isAdmin on the request
-- actor, independent of account_type). Flipping account_type only makes
-- AccountCapabilities + the dashboard reflect the staff status that already
-- exists in partna_staff.

-- ── Part 1: widen the CHECK to include 'staff' (idempotent; mirrors the DDL in
--    20260711000000 exactly, replayed here because it was found unapplied on
--    dev). DROP IF EXISTS → ADD NOT VALID → VALIDATE, same as 20260612120000. ──
ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;

ALTER TABLE core.users
    ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business', 'staff')) NOT VALID;

ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_check;

-- ── Part 2: backfill account_type='staff' for SITELESS staff members. ─────────
-- SAFETY — siteless only. A staff account has NO site (capabilities set
-- can_have_site=false). Flipping a partna_staff member who ALSO owns a site
-- would strand that site (it stays in site.sites but the account can no longer
-- edit or serve it). So this flips only staff members with zero sites; any staff
-- member who currently owns a site is deliberately LEFT as-is and must be
-- resolved by hand (decide whether they give up the site, or the dual role is
-- kept as an explicit exception) before flipping. As of authoring that excludes
-- exactly one member (handle 'joshhunter', 1 site).
UPDATE core.users u
SET account_type = 'staff',
    updated_at = now()
WHERE u.account_type <> 'staff'
  AND EXISTS (
      SELECT 1 FROM core.partna_staff ps
      WHERE ps.auth_user_id = u.auth_user_id
  )
  AND NOT EXISTS (
      SELECT 1 FROM site.sites s
      WHERE s.user_id = u.id
  );

-- Down (manual): the constraint may stay widened (harmless — 'staff' is a valid
-- type going forward). To revert only the data flip:
--   UPDATE core.users u SET account_type = 'partna'
--   WHERE u.account_type = 'staff'
--     AND EXISTS (SELECT 1 FROM core.partna_staff ps WHERE ps.auth_user_id = u.auth_user_id)
--     AND NOT EXISTS (SELECT 1 FROM site.sites s WHERE s.user_id = u.id);
