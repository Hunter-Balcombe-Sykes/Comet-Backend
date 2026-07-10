-- 20260711000200_feature_availability.sql
--
-- OV-A: feature/integration availability rules, staff-managed. One row per
-- (feature_key, segment) — segment_id NULL = the global row. Read side is
-- App\Services\FeatureAvailability\FeatureAvailability::for($user):
--   * segment-scoped rows the user belongs to beat the global row
--   * several matching segment rows: 'disabled' wins (deny wins)
--   * no matching row at all: the feature is AVAILABLE (absence = enabled)
-- Key convention: 'integration.<platform>' for integrations (consulted by the
-- /platforms/meta config endpoint), 'feature.<name>' for other gated surfaces.
--
-- Distinct concern from core.feature_flags (percentage rollout + per-user
-- overrides): availability is a staff-facing allow/deny matrix over segments.
-- Down: DROP TABLE IF EXISTS core.feature_availability;

CREATE TABLE IF NOT EXISTS core.feature_availability (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    feature_key text NOT NULL,
    mode        text NOT NULL CHECK (mode IN ('enabled', 'disabled')),
    segment_id  uuid REFERENCES core.user_segments(id) ON DELETE CASCADE,
    created_by  uuid REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT feature_availability_key_format CHECK (feature_key ~ '^[a-z][a-z0-9._-]*$')
);

COMMENT ON TABLE core.feature_availability IS
  'Staff-managed feature/integration availability. segment_id NULL = global row. Read via FeatureAvailability::for($user); absence of rows = available. Keys: integration.<platform> | feature.<name>.';

-- One global row per key; one row per (key, segment). Partial pair because
-- Postgres UNIQUE treats NULLs as distinct. New empty table — same-file
-- indexes are exempt from the CONCURRENTLY rule.
CREATE UNIQUE INDEX IF NOT EXISTS feature_availability_global_key_unique
    ON core.feature_availability (feature_key) WHERE segment_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS feature_availability_key_segment_unique
    ON core.feature_availability (feature_key, segment_id) WHERE segment_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS feature_availability_segment_idx
    ON core.feature_availability (segment_id) WHERE segment_id IS NOT NULL;

ALTER TABLE core.feature_availability ENABLE ROW LEVEL SECURITY;

GRANT SELECT, INSERT, UPDATE, DELETE ON core.feature_availability TO app_backend;

drop policy if exists feature_availability_staff_select on core.feature_availability;
create policy feature_availability_staff_select on core.feature_availability
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid() and ps.role in ('admin', 'support')
    ));

drop policy if exists feature_availability_service_role_all on core.feature_availability;
create policy feature_availability_service_role_all on core.feature_availability
    for all to service_role
    using (true) with check (true);
