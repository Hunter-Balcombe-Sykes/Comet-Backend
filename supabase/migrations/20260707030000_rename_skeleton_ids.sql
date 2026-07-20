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

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

-- DROP CONSTRAINT must precede the UPDATE below: it remaps values (bento,
-- hub, stories, flow) that the OLD CHECK forbids. Do not split this into
-- separate windows — that would open a window with no CHECK active on
-- site.sites.
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

-- Two-step CHECK add per CONVENTIONS.md §2: NOT VALID takes only a brief
-- metadata lock; VALIDATE below scans without blocking writes.
ALTER TABLE site.sites ADD CONSTRAINT sites_skeleton_id_check
    CHECK (skeleton_id IN ('bento', 'hub', 'stories', 'flow')) NOT VALID;

COMMIT;

ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;
