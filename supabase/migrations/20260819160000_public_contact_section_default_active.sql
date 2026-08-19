-- 20260819160000_public_contact_section_default_active.sql
--
-- RENUMBERED from 20260819140000 (2026-08-19). Three unrelated files landed on
-- that one timestamp — users_bio, drop_unreferenced_tables and this one — and
-- supabase_migrations.schema_migrations keys on the version alone, so exactly
-- ONE of them could ever be recorded. `users_bio` won (its column exists, and
-- 20260819140100_identity_mirror_backfill depends on it), which left this file
-- SILENTLY UNAPPLIED while `supabase migration list` showed 20260819140000 as
-- applied. Verified on dev: all 6 rows matching the predicate below were still
-- is_active = false. A collision does not error — it just loses a migration.
--
-- Public-contact section defaults ON (owner, 2026-08-19), matching the
-- `workplace` ruling earlier the same day. New rows start live
-- (UserSectionBlockController::SEEDED_LIVE_SECTIONS); this backfills the
-- existing ones.
--
-- WHY IT IS SAFE TO DEFAULT LIVE — the visibility rule, not this flag, decides
-- whether anything is published. PublicContactVisibility sets is_enabled only
-- once public_contact_number or public_contact_email is non-empty, and
-- SitepageDataResolverService::sectionEnvelope requires is_enabled AND
-- is_active. So flipping is_active on a user with no contact details changes
-- NOTHING on the wire — `publicContact` stays null. What it changes is what
-- happens when a detail later arrives: it appears, instead of sitting invisible
-- behind a switch the owner was never told about. Measured on dev 2026-08-19
-- ~04:00 UTC: of 12 users holding public contact data, only 2 were actually
-- rendering it. (Do not re-derive that ratio from a later snapshot without
-- checking it first — a bulk write at 07:45:52 the same day nulled
-- public_contact_* across most of dev, so counts taken after it measure that
-- event, not the steady state this migration is motivated by.)
--
-- NARROWED THE SAME WAY 20260819011500 WAS, and for a sharper reason. Turning
-- this section ON was impossible before; turning it OFF was not —
-- `PUT /api/sections/{blockType}` takes is_active straight from the client and
-- remove() sets it false outright. An unconditional flip would republish a
-- phone number or email address somebody deliberately hid. Worse than the
-- workplace case: these two columns are also written by AUTOMATED harvests
-- (a connected Google Business listing's phone; an email scraped off the
-- owner's previous website's contact page), so "the user put it there" is not
-- a safe assumption about the DATA either, only about the switch.
--
-- `updated_at = created_at` restricts the backfill to rows never written since
-- creation, which is what "still on the old default" actually means. It
-- UNDER-flips on purpose: any write bumps updated_at, including a system one,
-- so some genuine old-defaults are skipped. That is the safe direction — the
-- owner can switch those on themselves in one click, whereas a published phone
-- number cannot be un-published. Fail closed on privacy.
--
-- ROLLBACK: NONE. After this runs a deliberate "off" and the old default are
--   indistinguishable, so there is no state to restore — the same honest answer
--   20260819011500 gave. Recovery is per-user: switch the section back off.
BEGIN;
-- site.blocks is a HOT_TABLES member (CONVENTIONS.md / guard Check 5) — fail
-- fast on a busy lock rather than queueing behind live traffic.
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';
UPDATE site.blocks
   SET is_active = TRUE,
       updated_at = now()
 WHERE block_group = 'sections'
   AND block_type = 'public_contact'
   AND is_active = FALSE
   AND updated_at = created_at;
COMMIT;
