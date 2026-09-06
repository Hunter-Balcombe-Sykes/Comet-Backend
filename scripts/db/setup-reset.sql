-- setup-reset.sql — Get Started rebuild, file 00 pre-flight (Task 1).
--
-- Plain-SQL twin of `php artisan setup:reset {user} --yes [--rediscover]`,
-- for use through the Supabase MCP when artisan isn't at hand. Deletes every
-- user-scoped discovery/setup row for one TEST user, children before
-- parents — the same table list and column logic
-- `App\Console\Commands\SetupResetCommand::USER_TABLES` walks; keep the two
-- in sync if a later plan file adds a table there.
--
-- Refuses nothing here — there is no production guard in raw SQL. Never run
-- this against the prod project (edplucmvkcnokyygxqsb). Dev only
-- (glncumufgaqcmqhzwrxm).
--
-- Replace :user_id below with the target user's core.users.id (or resolve
-- it first: `select id, handle, primary_email from core.users where handle
-- = 'the-handle' or primary_email = 'the-handle' or id::text =
-- 'the-handle';` — do NOT compare `id = 'a-handle'` directly, Postgres
-- throws invalid-input-syntax-for-uuid for a non-UUID literal against a uuid
-- column, the same bug the artisan command itself hit live, 2026-09-07).

begin;

-- content.* — children before parents. collection_items/source_items use
-- content.sources (NOT ingest.sources — a different parent despite the same
-- column name; found in Task 1 review, 2026-09-07). item_links has no
-- user_id of its own (only item_id) — the artisan command relies on its
-- ON DELETE CASCADE from content.items; made explicit here instead, since a
-- raw-SQL run shouldn't depend on statement ordering (verified live,
-- 2026-09-07 dry run: `where user_id = ...` against item_links throws
-- "column user_id does not exist").
delete from content.item_links where item_id in (select id from content.items where user_id = :user_id);
delete from content.item_merges where user_id = :user_id;
delete from content.collection_items where source_id in (select id from content.sources where user_id = :user_id);
delete from content.item_anchors where user_id = :user_id;
delete from content.item_slugs where user_id = :user_id;
delete from content.identity_candidates where user_id = :user_id;
-- identity_keys has no user_id of its own either (only source_item_id ->
-- content.source_items) — same dry-run finding as item_links above.
delete from content.identity_keys where source_item_id in (
    select id from content.source_items where source_id in (select id from content.sources where user_id = :user_id)
);
delete from content.source_items where source_id in (select id from content.sources where user_id = :user_id);
delete from content.media_assets where user_id = :user_id;
delete from content.storefronts where user_id = :user_id;
delete from content.collections where user_id = :user_id;
delete from content.items where user_id = :user_id;
delete from content.sources where user_id = :user_id;

-- routing.*
delete from routing.item_tombstones where user_id = :user_id;
delete from routing.import_runs where user_id = :user_id;
delete from routing.source_intents where user_id = :user_id;

-- ingest.* — anomalies/streams/runs use ingest.sources (see the content.*
-- note above: same column name, different parent table per schema).
delete from ingest.anomalies where source_id in (select id from ingest.sources where user_id = :user_id);
delete from ingest.effects where source_id in (select id from ingest.sources where user_id = :user_id);
delete from ingest.record_versions where stream_id in (select id from ingest.streams where source_id in (select id from ingest.sources where user_id = :user_id));
delete from ingest.record_state where stream_id in (select id from ingest.streams where source_id in (select id from ingest.sources where user_id = :user_id));
delete from ingest.runs where source_id in (select id from ingest.sources where user_id = :user_id);
delete from ingest.streams where source_id in (select id from ingest.sources where user_id = :user_id);
delete from ingest.sources where user_id = :user_id;

-- site.*
delete from site.workplace_candidates where user_id = :user_id;
delete from site.workplaces where site_id in (select id from site.sites where user_id = :user_id);
delete from site.menus where user_id = :user_id;
delete from site.platform_connections where user_id = :user_id;

-- core.pre_account_build_events — via build_id, not user_id directly (the
-- table has no user_id column of its own).
delete from core.pre_account_build_events where build_id in (select id from core.pre_account_builds where user_id = :user_id);

-- site.section_items has no user_id/site_id of its own either — reached
-- through site.sections.site_id, same as the artisan command.
delete from site.section_items where section_id in (
    select s.id from site.sections s
    join site.sites st on st.id = s.site_id
    where st.user_id = :user_id
);

-- Clear the setup bookmark so the walk starts fresh.
update site.sites set setup_step = null, setup_completed_at = null where user_id = :user_id;

commit;

-- ── OPTIONAL: --rediscover equivalent (run manually, NOT part of the
-- reset above — this is a separate step, matching the artisan command's
-- `--rediscover` flag being opt-in). core.pre_account_builds.user_id is
-- UNIQUE — there is exactly one build row per user, ever, so this UPDATEs
-- the existing row back to pending/unclaimed rather than inserting a
-- second one (which throws a unique-constraint violation for any
-- already-claimed account — found live, 2026-09-07).
--
-- update core.pre_account_builds
-- set build_state = 'pending',
--     failure_code = null,
--     claimed_at = null,
--     content_filled_at = null,
--     enriched_at = null,
--     settled_at = null,
--     setup_stalled_at = null,
--     welcomed_at = null,
--     thin_scrape_at = null,
--     published_by_claim = false,
--     -- created_at MUST also become "now": BuildProgressReader's ceiling
--     -- check and setup:timing's elapsed marks both key off it as "run
--     -- start" — left stale, every replay after the first reports
--     -- nonsense numbers (observed: all_ready_s = 3199s against an actual
--     -- ~256s run) and the live dashboard poll sees the build as already
--     -- timed out from the moment it's dispatched.
--     created_at = now(),
--     updated_at = now()
-- where user_id = :user_id;
--
-- Then dispatch GeneratePreAccountSiteJob for that build id — raw SQL
-- cannot push to the Redis queue, so this half of --rediscover has no SQL
-- equivalent. Use the artisan command for a real rediscover run; this file
-- is for the plain wipe only when artisan isn't reachable.
