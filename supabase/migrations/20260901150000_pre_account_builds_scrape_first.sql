-- Item 1a (2026-09-01, scrape-first builds): the source scrape now runs BEFORE
-- any identity is allocated, so a build row exists with no user for its first
-- seconds — and permanently when the source turns out not to exist (the
-- bydannydixon class: a failed build must never squat a handle again).
--
--   * user_id becomes nullable. The FK itself stays; a failed pre-identity
--     build simply has no user to point at.
--   * account_type and source_name move onto the build row: they were
--     request-time facts consumed at user-creation time, which now happens
--     inside the job after the scrape verifies the source.
--
-- Backfill: existing rows all carry a user already (the old sequence created
-- it first), so account_type/source_name stay null for them — every consumer
-- reads them only on the new path, where they are always written.
--
-- ROLLBACK: ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS source_name;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS account_type;
--           (user_id SET NOT NULL is deliberately NOT part of the reverse: rows
--           created identity-less by the new code would violate it — delete
--           user_id-null builds first if a full reverse is ever required.)

alter table core.pre_account_builds alter column user_id drop not null;

alter table core.pre_account_builds add column if not exists account_type text;

alter table core.pre_account_builds add column if not exists source_name text;
