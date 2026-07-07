-- Rename skeleton ids from positional (skeleton-1..4) to semantic names
-- (2026-07-07). The Astro pages app normalizes legacy ids at read time
-- (normalizeSkeletonId), and both site-update FormRequests normalize legacy
-- ids on write, so this can apply while old clients are still live.
--
--   skeleton-1 → bento    (bento card grid, FLIP expand)
--   skeleton-2 → hub      (home hub + pill tab bar + panel track)
--   skeleton-3 → stories  (horizontal story deck + progress rail)
--   skeleton-4 → flow     (vertical fullscreen snap flow)

BEGIN;

ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_skeleton_id_check;

UPDATE site.sites
SET skeleton_id = CASE skeleton_id
    WHEN 'skeleton-1' THEN 'bento'
    WHEN 'skeleton-2' THEN 'hub'
    WHEN 'skeleton-3' THEN 'stories'
    WHEN 'skeleton-4' THEN 'flow'
    ELSE skeleton_id
END
WHERE skeleton_id LIKE 'skeleton-%';

ALTER TABLE site.sites ALTER COLUMN skeleton_id SET DEFAULT 'bento';

-- Table is small (pre-launch) and rows were normalized in this transaction —
-- a directly-validated ADD CONSTRAINT is safe here; no NOT VALID two-step.
ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('bento', 'hub', 'stories', 'flow'));

COMMIT;
