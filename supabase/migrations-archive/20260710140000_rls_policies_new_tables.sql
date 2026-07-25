-- RLS-policy alignment for the three newest tables (Supabase security advisor:
-- rls_enabled_no_policy). Every sibling table in analytics/site carries RLS +
-- policies; these were created without the boilerplate. app_backend (table
-- owner) bypasses RLS either way — these policies govern the PostgREST roles
-- (anon/authenticated/service_role) for defence-in-depth, mirroring the shapes
-- already on analytics.section_views / site.design_kits.

-- ── analytics.item_views (user_id grain — mirrors section_views exactly) ────
drop policy if exists item_views_owner_select on analytics.item_views;
create policy item_views_owner_select on analytics.item_views
    for select to authenticated
    using (user_id = (
        select users.id from core.users
        where users.auth_user_id = auth.uid() and users.deleted_at is null
    ));

drop policy if exists item_views_staff_select on analytics.item_views;
create policy item_views_staff_select on analytics.item_views
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid() and ps.role in ('admin', 'support')
    ));

drop policy if exists item_views_service_role_all on analytics.item_views;
create policy item_views_service_role_all on analytics.item_views
    for all to service_role
    using (true) with check (true);

-- ── analytics.content_popularity_scores (site_id grain — owner via sites) ───
drop policy if exists content_popularity_scores_owner_select on analytics.content_popularity_scores;
create policy content_popularity_scores_owner_select on analytics.content_popularity_scores
    for select to authenticated
    using (exists (
        select 1
        from site.sites s
        join core.users u on u.id = s.user_id
        where s.id = content_popularity_scores.site_id
          and u.auth_user_id = auth.uid()
          and u.deleted_at is null
    ));

drop policy if exists content_popularity_scores_staff_select on analytics.content_popularity_scores;
create policy content_popularity_scores_staff_select on analytics.content_popularity_scores
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid() and ps.role in ('admin', 'support')
    ));

drop policy if exists content_popularity_scores_service_role_all on analytics.content_popularity_scores;
create policy content_popularity_scores_service_role_all on analytics.content_popularity_scores
    for all to service_role
    using (true) with check (true);

-- ── site.design_kit_contributions (site_id grain; backend-written provenance —
--    owner + staff read only, no client-side writes) ──────────────────────────
drop policy if exists design_kit_contributions_owner_select on site.design_kit_contributions;
create policy design_kit_contributions_owner_select on site.design_kit_contributions
    for select to authenticated
    using (exists (
        select 1
        from site.sites s
        join core.users u on u.id = s.user_id
        where s.id = design_kit_contributions.site_id
          and u.auth_user_id = auth.uid()
          and u.deleted_at is null
    ));

drop policy if exists design_kit_contributions_staff_select on site.design_kit_contributions;
create policy design_kit_contributions_staff_select on site.design_kit_contributions
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid()
    ));

drop policy if exists design_kit_contributions_service_role_all on site.design_kit_contributions;
create policy design_kit_contributions_service_role_all on site.design_kit_contributions
    for all to service_role
    using (true) with check (true);
