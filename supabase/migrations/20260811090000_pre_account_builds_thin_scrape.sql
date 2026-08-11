-- core.pre_account_builds.thin_scrape_at — stamped when a build's Instagram
-- scrape came back with no post timeline (spec 2026-08-11).
--
-- The build stays build_state='ready'. A thin site still renders, and a
-- genuinely sparse account must never be told its build failed — so this is a
-- separate "looks suspect" axis, not a new build state. build_state carries
-- this table's only CHECK constraint and is deliberately NOT widened.
--
-- Not folded into failure_code: that column is documented as meaningful only
-- when build_state='failed', and UserStaffResource + PreAccountBuildStatusResource
-- pass it straight to the wire, so a 'ready' build carrying a failure code would
-- read as broken in the staff UI.
--
-- Nullable, no default, no index: reads are per-build or a small staff-side
-- scan, and the table is low-cardinality.
--
-- ROLLBACK: ALTER TABLE core.pre_account_builds DROP COLUMN thin_scrape_at;

ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS thin_scrape_at timestamptz NULL;
