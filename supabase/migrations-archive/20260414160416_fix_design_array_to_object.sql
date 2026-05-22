-- Repair site.settings.design on any rows where it ended up as a JSON array
-- instead of an object.
--
-- Why: a small number of brand sites had `settings.design` stored as
--   [{ "key1": "val1" }, { "key2": "val2" }, ...]
-- rather than
--   { "key1": "val1", "key2": "val2", ... }
-- The sibling 20260414160327_unify_brand_design_storage migration assumes
-- design is an object and was skipping these rows (guarded by jsonb_typeof).
-- This hotfix merges each array element back into a single object so the
-- unified shape applies to every site.
--
-- How: jsonb_array_elements expands the array into one row per element, then
-- jsonb_each breaks each element into its key/value pairs, and
-- jsonb_object_agg rebuilds the flat object. Safe to re-run — the WHERE
-- clause skips rows that are already objects.

UPDATE site.sites
SET settings = jsonb_set(
    settings,
    '{design}',
    (
        SELECT jsonb_object_agg(kv.k, kv.v)
        FROM (
            SELECT (je).key AS k, (je).value AS v
            FROM jsonb_array_elements(settings->'design') AS elem,
                 LATERAL jsonb_each(elem) AS je
        ) kv
    ),
    true
)
WHERE jsonb_typeof(settings) = 'object'
  AND jsonb_typeof(settings->'design') = 'array';
