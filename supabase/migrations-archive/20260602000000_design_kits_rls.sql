-- Enable Row Level Security on site.design_kits.
-- All other site.* tables have RLS; design_kits was the lone exception
-- created in 20260527070000_skeleton_system_cleanup.sql.
-- Policies mirror the site.sites pattern: authenticated users see their own
-- kit (and staff see all); write access is owner + staff only.
-- The anon SELECT policy covers the public profile page read path.

ALTER TABLE site.design_kits ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.design_kits FORCE ROW LEVEL SECURITY;

-- SELECT: own kit OR staff OR the site is published (public profile read path)
CREATE POLICY design_kits_select_authenticated ON site.design_kits
    FOR SELECT TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM site.sites s
            JOIN core.users p ON p.id = s.user_id
            WHERE s.id = design_kits.site_id
              AND (
                  s.is_published = true
                  OR (p.auth_user_id = auth.uid() AND p.deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())
              )
        )
    );

-- Anon SELECT: only published sites (serves the public profile page)
CREATE POLICY design_kits_public_read_published ON site.design_kits
    FOR SELECT TO anon
    USING (
        EXISTS (
            SELECT 1 FROM site.sites s
            WHERE s.id = design_kits.site_id AND s.is_published = true
        )
    );

-- INSERT: own site or staff
CREATE POLICY design_kits_insert_authenticated ON site.design_kits
    FOR INSERT TO authenticated
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM site.sites s
            JOIN core.users p ON p.id = s.user_id
            WHERE s.id = design_kits.site_id
              AND (
                  (p.auth_user_id = auth.uid() AND p.deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())
              )
        )
    );

-- UPDATE: own site or staff (USING and WITH CHECK both required)
CREATE POLICY design_kits_update_authenticated ON site.design_kits
    FOR UPDATE TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM site.sites s
            JOIN core.users p ON p.id = s.user_id
            WHERE s.id = design_kits.site_id
              AND (
                  (p.auth_user_id = auth.uid() AND p.deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())
              )
        )
    )
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM site.sites s
            JOIN core.users p ON p.id = s.user_id
            WHERE s.id = design_kits.site_id
              AND (
                  (p.auth_user_id = auth.uid() AND p.deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())
              )
        )
    );

-- DELETE: own site or staff
CREATE POLICY design_kits_delete_authenticated ON site.design_kits
    FOR DELETE TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM site.sites s
            JOIN core.users p ON p.id = s.user_id
            WHERE s.id = design_kits.site_id
              AND (
                  (p.auth_user_id = auth.uid() AND p.deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())
              )
        )
    );
