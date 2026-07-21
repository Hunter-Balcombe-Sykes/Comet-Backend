-- Backfill any site.sites row missing its 1:1 site.design_kits row.
--
-- trg_create_empty_design_kit (added in 20260527070000_skeleton_system_cleanup)
-- auto-inserts an empty design_kits row on every site.sites INSERT, but it
-- only fires going forward — sites created before that migration landed
-- were backfilled separately at the time via a one-shot UpdateOrInsert-style
-- pass, not this trigger. That backfill and any subsequent app-level
-- updateOrInsert() write race (read-miss -> insert) can still leave a site
-- with no design_kits row if the two ran concurrently. This migration closes
-- that residual gap idempotently: NOT EXISTS skips every site that already
-- has a kit, so re-running is always a no-op.
--
-- Per CONVENTIONS.md #5, this backfill runs outside any BEGIN/COMMIT block —
-- no lock is held for the duration of the scan/insert.
INSERT INTO site.design_kits (site_id)
SELECT s.id
FROM site.sites s
WHERE NOT EXISTS (
    SELECT 1 FROM site.design_kits d WHERE d.site_id = s.id
);
