-- RLS-policy alignment for site.workplaces and site.content_selection
-- (Supabase security advisor: rls_disabled_in_public). Both tables were
-- created with RLS OFF entirely — unlike the sibling tables closed out in
-- 20260710140000_rls_policies_new_tables.sql, which had RLS enabled but no
-- policies. app_backend (table owner) bypasses RLS either way — these
-- policies govern the PostgREST roles (anon/authenticated/service_role) for
-- defence-in-depth, mirroring the shapes already on analytics.section_views /
-- site.design_kit_contributions.
--
-- Both tables are site-scoped (site.workplaces.site_id is itself the PK,
-- 1:1 with site.sites; site.content_selection.site_id is a plain FK column),
-- so owner resolution joins through site.sites -> core.users exactly like
-- the content_popularity_scores / design_kit_contributions policies below.

ALTER TABLE site.workplaces ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.workplaces FORCE ROW LEVEL SECURITY;

-- ── site.workplaces (site_id grain — PK is site_id, owner via sites) ────────
drop policy if exists workplaces_owner_select on site.workplaces;
create policy workplaces_owner_select on site.workplaces
    for select to authenticated
    using (exists (
        select 1
        from site.sites s
        join core.users u on u.id = s.user_id
        where s.id = workplaces.site_id
          and u.auth_user_id = auth.uid()
          and u.deleted_at is null
    ));

drop policy if exists workplaces_staff_select on site.workplaces;
create policy workplaces_staff_select on site.workplaces
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid() and ps.role in ('admin', 'support')
    ));

drop policy if exists workplaces_service_role_all on site.workplaces;
create policy workplaces_service_role_all on site.workplaces
    for all to service_role
    using (true) with check (true);

ALTER TABLE site.content_selection ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.content_selection FORCE ROW LEVEL SECURITY;

-- ── site.content_selection (site_id grain — owner via sites) ───────────────
drop policy if exists content_selection_owner_select on site.content_selection;
create policy content_selection_owner_select on site.content_selection
    for select to authenticated
    using (exists (
        select 1
        from site.sites s
        join core.users u on u.id = s.user_id
        where s.id = content_selection.site_id
          and u.auth_user_id = auth.uid()
          and u.deleted_at is null
    ));

drop policy if exists content_selection_staff_select on site.content_selection;
create policy content_selection_staff_select on site.content_selection
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid() and ps.role in ('admin', 'support')
    ));

drop policy if exists content_selection_service_role_all on site.content_selection;
create policy content_selection_service_role_all on site.content_selection
    for all to service_role
    using (true) with check (true);
