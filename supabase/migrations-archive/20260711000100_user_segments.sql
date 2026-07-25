-- 20260711000100_user_segments.sql
--
-- OV-A: staff-defined user segments. A segment = a dynamic filter definition
-- (JSONB, evaluated live by App\Services\Segments\SegmentResolver) plus an
-- optional manual member list. Consumed by staff notifications (audience),
-- feature availability (segment-scoped rules) and aggregate analytics scope.
--
-- filters JSONB shape (all keys optional; missing/null = unconstrained; keys
-- AND-combine; zero dynamic keys = manual-members-only segment):
--   { "account_type": "partna"|"business"|"staff",
--     "sector": ["hairdresser", ...],
--     "created_from": "YYYY-MM-DD", "created_to": "YYYY-MM-DD",
--     "has_integration": true | "<platform>",
--     "early_access": true|false,
--     "include_manual_members": true (default) }
--
-- app_backend bypasses RLS (rolbypassrls=t); RLS-on + staff/service policies is
-- the live-safe posture mirroring 20260710140000_rls_policies_new_tables.sql.
-- Down: DROP TABLE IF EXISTS core.user_segment_members; DROP TABLE IF EXISTS core.user_segments;

CREATE TABLE IF NOT EXISTS core.user_segments (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name        text NOT NULL,
    description text,
    filters     jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_by  uuid REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT user_segments_filters_is_object CHECK (jsonb_typeof(filters) = 'object')
);

COMMENT ON TABLE core.user_segments IS
  'Staff-defined user segments: dynamic JSONB filter definition + manual members (core.user_segment_members). Resolved live to a user-id set by SegmentResolver; used for staff notifications, feature availability scoping, aggregate analytics.';

CREATE TABLE IF NOT EXISTS core.user_segment_members (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    segment_id uuid NOT NULL REFERENCES core.user_segments(id) ON DELETE CASCADE,
    user_id    uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
    added_by   uuid REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT user_segment_members_unique UNIQUE (segment_id, user_id)
);

COMMENT ON TABLE core.user_segment_members IS
  'Manual segment membership rows — additive on top of the segment''s dynamic filters.';

-- New empty tables — same-file, non-concurrent indexes are exempt from the
-- CONCURRENTLY rule (no data, no traffic at create time).
CREATE INDEX IF NOT EXISTS user_segment_members_user_idx ON core.user_segment_members (user_id);

ALTER TABLE core.user_segments ENABLE ROW LEVEL SECURITY;
ALTER TABLE core.user_segment_members ENABLE ROW LEVEL SECURITY;

GRANT SELECT, INSERT, UPDATE, DELETE ON core.user_segments TO app_backend;
GRANT SELECT, INSERT, UPDATE, DELETE ON core.user_segment_members TO app_backend;

-- Defence-in-depth policies for the PostgREST roles (advisor: rls_enabled_no_policy).
drop policy if exists user_segments_staff_select on core.user_segments;
create policy user_segments_staff_select on core.user_segments
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid() and ps.role in ('admin', 'support')
    ));

drop policy if exists user_segments_service_role_all on core.user_segments;
create policy user_segments_service_role_all on core.user_segments
    for all to service_role
    using (true) with check (true);

drop policy if exists user_segment_members_staff_select on core.user_segment_members;
create policy user_segment_members_staff_select on core.user_segment_members
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid() and ps.role in ('admin', 'support')
    ));

drop policy if exists user_segment_members_service_role_all on core.user_segment_members;
create policy user_segment_members_service_role_all on core.user_segment_members
    for all to service_role
    using (true) with check (true);
