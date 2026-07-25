-- =====================================================================
-- site.design_kit_contributions — per-factor design-kit preset contributions
-- =====================================================================
-- Integration "factors" (e.g. a Google Business business-type) emit sparse
-- contributions: one row per (factor source × target design-kit column).
-- The priority-merge of a site's rows is the "preset layer" that sits BETWEEN
-- the package defaults and the user's manual design_kits values:
--
--     package defaults  <-  preset layer  <-  manual (site.design_kits, wins)
--
-- The layer is merged at read time server-side (DesignPresetResolver) — it is
-- NEVER written into design_kits, so a non-null manual column always wins its
-- own value. Rows are (re)built on integration connect / refresh / disconnect.
-- One-shot factors freeze at connect (kept as-is across rebuilds); disconnect
-- deletes the integration's rows and columns re-resolve to the next source.
--
-- ACCESS: only app_backend touches this table (the server-side resolver + the
-- public-profile payload builder). RLS is enabled with NO permissive policies:
-- app_backend has BYPASSRLS so it operates freely, while anon/authenticated
-- get no direct access (least privilege — the public reads MERGED values via
-- the profile payload, never this table; there is no dashboard consumer).

CREATE TABLE IF NOT EXISTS site.design_kit_contributions (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    site_id       uuid NOT NULL REFERENCES site.sites (id) ON DELETE CASCADE,
    source        text NOT NULL,          -- stable factor key (provenance + upsert unit)
    integration   text NOT NULL,          -- platform slug (delete unit on disconnect)
    priority      integer NOT NULL,       -- higher wins a contested column
    mode          text NOT NULL CHECK (mode IN ('one_shot', 'auto')),
    target_var    text NOT NULL,          -- design_kits column name (value/selection var only)
    value         text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    -- A factor sets each target column at most once.
    UNIQUE (site_id, source, target_var)
);

-- Read path merges by site_id; disconnect deletes by (site_id, integration).
CREATE INDEX IF NOT EXISTS idx_design_kit_contributions_site
    ON site.design_kit_contributions (site_id);

CREATE INDEX IF NOT EXISTS idx_design_kit_contributions_site_integration
    ON site.design_kit_contributions (site_id, integration);

-- Only the backend role touches this table (post-baseline tables need their
-- own grant — the baseline ON ALL TABLES grant does not cover them).
GRANT SELECT, INSERT, UPDATE, DELETE ON site.design_kit_contributions TO app_backend;

-- RLS on, no policies: app_backend (BYPASSRLS) works; every other role denied.
ALTER TABLE site.design_kit_contributions ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.design_kit_contributions FORCE ROW LEVEL SECURITY;
