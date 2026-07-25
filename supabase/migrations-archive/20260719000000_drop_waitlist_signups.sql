-- 20260719000000_drop_waitlist_signups.sql
--
-- Retire the V2 waitlist capture path. core.early_access_signups
-- (20260711000300) superseded it with a full waitlist → invited → signed_up
-- lifecycle plus staff CRUD; core.waitlist_signups had no read path at all —
-- its only consumers were its own prune command, the account-deletion purge,
-- and the GDPR export, all repointed at early_access_signups on 2026-07-19.
--
-- The single remaining row (1 signup, 2026-05-26, all optional fields null)
-- is dropped deliberately: early_access_signups.type is NOT NULL CHECK IN
-- ('partna','business') and the source row's applicant_type was null, so no
-- faithful migration exists.
--
-- Down: no restore. The table is gone and its data is not recoverable from
-- this migration; restore from a Supabase PITR snapshot if ever needed.

DROP TABLE IF EXISTS core.waitlist_signups;
