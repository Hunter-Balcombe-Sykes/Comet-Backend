-- Rename the vestigial skeleton_id column to architecture_id. An
-- "architecture" is how the sitepage is laid out / how its pages connect;
-- design variation lives entirely in site.design_kits. The value is still
-- always 'one' (single-architecture platform) — this is a pure identifier
-- rename tracking the cross-repo "skeleton -> architecture" vocabulary shift.
--
-- Catalog-only: RENAME COLUMN / RENAME CONSTRAINT / ALTER VIEW RENAME COLUMN
-- are all metadata updates — instant, no table rewrite, no row scan, no lock
-- risk. Safe to apply BEFORE the app deploy: old code reading a now-missing
-- `skeleton_id` attribute gets null and every read path falls back to
-- DEFAULT_ARCHITECTURE_ID ('one'); there are no live writers of a non-'one'
-- value.
BEGIN;

ALTER TABLE site.sites RENAME COLUMN skeleton_id TO architecture_id;
ALTER TABLE site.sites RENAME CONSTRAINT sites_skeleton_id_check TO sites_architecture_id_check;

-- site.all_site_data selects skeleton_id as a top-level column (it backs the
-- AllSiteData model -> StaffSiteResource). Postgres auto-rewrites the view's
-- internal column reference on the base rename, but the view's OUTPUT column
-- keeps its old name — so AllSiteData->architecture_id would resolve to null
-- until we rename it here. CREATE OR REPLACE VIEW cannot rename an existing
-- column ("cannot change name of view column"), so use ALTER VIEW RENAME
-- COLUMN — catalog-only, no body rewrite, no re-grant needed.
ALTER VIEW site.all_site_data RENAME COLUMN skeleton_id TO architecture_id;

-- NOTE: site.public_site_payload embeds 'skeleton_id' only as a JSONB
-- string-literal key (payload->'site'->>'skeleton_id' and payload->>'skeleton_id').
-- A base-column rename does NOT touch those literal keys, so that view keeps
-- emitting skeleton_id and its consumers (SiteCacheService / partna-pages) are
-- unaffected. Renaming that public wire key is a separate, consumer-coordinated
-- change and is intentionally out of scope here.

COMMIT;
