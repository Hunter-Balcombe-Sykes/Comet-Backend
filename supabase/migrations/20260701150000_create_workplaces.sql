-- =====================================================================
-- Workplace card — promote site.sites.settings->'workplace' JSONB → table
-- =====================================================================
-- The workplace card is a known, validated, typed set of 14 named fields
-- (UpsertWorkplaceRequest). Stored in the site.sites.settings JSONB it forced a
-- non-indexable `settings->'workplace'->>'name'` scan on every public-page
-- visibility check. Promote it to a 1:1 table keyed by site_id.
--
-- name is NULLABLE on purpose: setPreviousWebsite + GoogleBusinessAutoSync seed
-- previous_website/category/description with no name; a name-less row renders as
-- null everywhere (resolver + visibility gate on a non-empty name).

CREATE TABLE IF NOT EXISTS site.workplaces (
    site_id          uuid PRIMARY KEY REFERENCES site.sites (id) ON DELETE CASCADE,
    name             text,
    address          text,
    address_line1    text,
    city             text,
    state            text,
    postcode         text,
    country          text,
    latitude         double precision,
    longitude        double precision,
    phone            text,
    website          text,
    previous_website text,
    category         text,
    description      text,
    created_at       timestamptz,
    updated_at       timestamptz
);

-- Backfill every workplace object faithfully (name may be null). No-op pre-beta
-- (zero rows) but correct for prod-shape parity. NULLIF(...,'') keeps empty
-- strings out; numeric casts guard against '' → 0.0.
INSERT INTO site.workplaces (
    site_id, name, address, address_line1, city, state, postcode, country,
    latitude, longitude, phone, website, previous_website, category, description,
    created_at, updated_at
)
SELECT
    s.id,
    NULLIF(btrim(s.settings->'workplace'->>'name'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'address'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'address_line1'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'city'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'state'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'postcode'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'country'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'latitude'), '')::double precision,
    NULLIF(btrim(s.settings->'workplace'->>'longitude'), '')::double precision,
    NULLIF(btrim(s.settings->'workplace'->>'phone'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'website'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'previous_website'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'category'), ''),
    NULLIF(btrim(s.settings->'workplace'->>'description'), ''),
    now(), now()
FROM site.sites s
WHERE s.settings ? 'workplace'
  AND jsonb_typeof(s.settings->'workplace') = 'object';

-- Strip the promoted key from settings.
UPDATE site.sites
SET settings = settings - 'workplace'
WHERE settings ? 'workplace';

-- ROLLBACK:
-- UPDATE site.sites s
-- SET settings = jsonb_set(
--         COALESCE(s.settings, '{}'::jsonb),
--         '{workplace}',
--         jsonb_strip_nulls(jsonb_build_object(
--             'name', w.name, 'address', w.address, 'address_line1', w.address_line1,
--             'city', w.city, 'state', w.state, 'postcode', w.postcode, 'country', w.country,
--             'latitude', w.latitude, 'longitude', w.longitude, 'phone', w.phone,
--             'website', w.website, 'previous_website', w.previous_website,
--             'category', w.category, 'description', w.description
--         ))
--     )
-- FROM site.workplaces w
-- WHERE w.site_id = s.id;
-- DROP TABLE IF EXISTS site.workplaces;
