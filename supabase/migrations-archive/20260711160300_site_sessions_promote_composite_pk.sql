-- #DINT-1 step 3 of 3 — promote the (id, site_id) unique index added by
-- 20260711160200_site_sessions_add_composite_unique.sql to
-- analytics.site_sessions' primary key, replacing the old PRIMARY KEY (id)
-- alone.
--
-- *** APPLY ORDER — DO NOT run this migration until the app deploy carrying
-- PostgresEventWriter::upsertSession()'s `ON CONFLICT (id, site_id)` (which
-- replaces `ON CONFLICT (id) ... WHERE site_id = EXCLUDED.site_id`) is LIVE
-- on development. Applying this first, while the OLD app is still writing
-- `ON CONFLICT (id)`, leaves that app's upsert with no matching arbiter
-- index (PRIMARY KEY (id) alone will already be gone) — every ping would
-- error at ON CONFLICT resolution instead of upserting. Full sequence:
--   1. (20260711160200) — safe with the CURRENT live app, already applied.
--   2. `git push development` carrying the PostgresEventWriter change.
--   3. (this file) — only after step 2's deploy is confirmed live.
-- All three steps target dev (glncumufgaqcmqhzwrxm) only — analytics.site_
-- sessions does not exist in the same state on the paused production project
-- (see CLAUDE.md's Laravel Cloud reality note); never run this against prod.
--
-- WHY THIS IS SAFE / WHAT IT DOES:
--   `site_id` is `uuid NOT NULL` in the table's original DDL
--   (20260610000000_analytics_v2_clicks_sessions.sql, CREATE TABLE) and `id`
--   is NOT NULL as the pre-existing single-column PK — both columns already
--   satisfy `PRIMARY KEY USING INDEX`'s NOT NULL requirement, so this
--   migration needs no `ALTER COLUMN ... SET NOT NULL` and no backfill.
--   `ADD CONSTRAINT ... PRIMARY KEY USING INDEX site_sessions_id_site_uk`
--   adopts the bare unique index built by 20260711160200 instead of
--   rebuilding one — verified (Postgres 16) that this renames the index to
--   the constraint's name (site_sessions_pkey), and there is no leftover
--   duplicate index afterward (`\d` shows a single index, still named
--   site_sessions_pkey, now covering (id, site_id)).
--
-- After this migration: uniqueness on `id` ALONE is gone — intentional. Two
-- different sites may now legitimately hold a session row with the same
-- client-minted session id; this is the fix for #DINT-1 (a second site's
-- heartbeat used to be silently dropped when that happened).
--
-- ROLLBACK: safe only if no row written after this migration relies on the
-- new (id, site_id) uniqueness — i.e. no two rows currently share an `id`
-- with different `site_id` values (exactly the case this migration exists
-- to allow). If any such rows exist, restoring PRIMARY KEY (id) alone will
-- fail with a duplicate-key error until they are reconciled or deleted.
--   BEGIN;
--   ALTER TABLE analytics.site_sessions DROP CONSTRAINT site_sessions_pkey;
--   ALTER TABLE analytics.site_sessions ADD CONSTRAINT site_sessions_pkey PRIMARY KEY (id);
--   COMMIT;
--
-- LOCKING: metadata-only catalog operations. Dropping a PK that nothing else
-- depends on (no FK from another table references site_sessions.id — the
-- audit confirmed this) and then attaching an already-built, already-valid
-- index as the new PK is near-instant regardless of table size — no
-- full-table rewrite or scan, since both key columns are already NOT NULL
-- and the index already exists.

BEGIN;

ALTER TABLE analytics.site_sessions DROP CONSTRAINT site_sessions_pkey;

ALTER TABLE analytics.site_sessions
    ADD CONSTRAINT site_sessions_pkey PRIMARY KEY USING INDEX site_sessions_id_site_uk;

COMMIT;
