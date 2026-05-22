-- Rename the middle value of every design bucket to `default` so the stored
-- vocabulary matches the dashboard dropdown labels (Square / Default / Pill,
-- Hairline / Default / Bold, Tight / Default / Spacious).
--
-- Why: the dashboard's "Default" option for corner_radius previously sent
-- `rounded` and for border_thickness sent `standard`. That mismatch made
-- support hard ("user picked Default but the DB says rounded") and meant
-- Hydrogen had to know two different vocabularies. Section spacing already
-- used `default` so this only touches the other two buckets.
--
-- After this migration:
--   corner_radius:    square | default | pill
--   border_thickness: hairline | default | bold
--   section_spacing:  tight | default | spacious   (unchanged)
--
-- Backend Form Request validation, the Shopify importer, the Brand Design
-- controller defaults, the Hydrogen coercer, and the dashboard option list
-- are all updated in lockstep with this migration.

BEGIN;

UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design,corner_radius}',
    '"default"'::jsonb,
    false
)
WHERE settings -> 'design' ->> 'corner_radius' = 'rounded';

UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design,border_thickness}',
    '"default"'::jsonb,
    false
)
WHERE settings -> 'design' ->> 'border_thickness' = 'standard';

COMMIT;

-- NOTE: HydrogenBrandDesignController caches its response for 5 minutes per
-- brand (see CacheKeyGenerator::brandDesignConfig). Cached payloads written
-- before this migration ran will still contain the old values until the TTL
-- expires. Run `php artisan cache:clear` (or wait ~5 min) to flush.
