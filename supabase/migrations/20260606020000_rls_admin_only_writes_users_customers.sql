-- RLS hardening (audit #P2-04): gate staff writes on core.users and
-- site.customers to ADMIN staff only.
--
-- WHY: the old `users_all_authenticated` / `customers_all_authenticated`
-- policies were FOR ALL with a bare staff-membership check
-- (EXISTS partna_staff WHERE auth_user_id = auth.uid()) and NO role filter.
-- A compromised or rogue `support` staff account holding a direct Supabase
-- JWT could therefore INSERT/UPDATE/DELETE any user profile or customer row.
-- core.partna_staff already gates its own writes on `cs.role = 'admin'`;
-- these two tables did not.
--
-- IMPORTANT RLS nuance: in PostgreSQL, WITH CHECK governs INSERT/UPDATE
-- (the NEW row) while USING governs SELECT/UPDATE/DELETE (which EXISTING
-- rows are affectable). Adding `role = 'admin'` only to WITH CHECK would
-- leave DELETE wide open, because DELETE is gated by USING. This migration
-- therefore splits each FOR ALL policy into:
--   * FOR SELECT  → any staff OR the owner (read access unchanged),
--   * FOR INSERT  → owner OR admin staff (WITH CHECK),
--   * FOR UPDATE  → owner OR admin staff (BOTH USING and WITH CHECK),
--   * FOR DELETE  → owner OR admin staff (USING) — closes the DELETE gap.
-- The owner branch is preserved verbatim so users keep full control of
-- their own rows; only the staff escalation is tightened to admin.
--
-- app_backend (the Laravel runtime role) has BYPASSRLS, so these policies
-- apply ONLY to the `authenticated` role (direct Supabase JWT clients).
-- App-side writes are unaffected by this change.
--
-- DROP/CREATE POLICY are catalog-only metadata operations (no table scan,
-- no row locks held for any meaningful duration) — safe on populated tables.

BEGIN;

-- ── core.users ────────────────────────────────────────────────────────────
DROP POLICY IF EXISTS users_all_authenticated ON core.users;

-- SELECT: own row OR any staff (read access unchanged from the old policy).
CREATE POLICY users_select_authenticated ON core.users
    FOR SELECT TO authenticated
    USING (
        (auth_user_id = auth.uid())
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())
    );

-- INSERT: own row OR admin staff.
CREATE POLICY users_insert_owner_or_admin ON core.users
    FOR INSERT TO authenticated
    WITH CHECK (
        (auth_user_id = auth.uid())
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')
    );

-- UPDATE: own row OR admin staff. Both USING (which existing rows) and
-- WITH CHECK (what the row may become) are required for a complete gate.
CREATE POLICY users_update_owner_or_admin ON core.users
    FOR UPDATE TO authenticated
    USING (
        (auth_user_id = auth.uid())
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')
    )
    WITH CHECK (
        (auth_user_id = auth.uid())
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')
    );

-- DELETE: own row OR admin staff. Gated via USING (DELETE has no WITH CHECK).
-- This is the branch a WITH-CHECK-only fix would have missed.
CREATE POLICY users_delete_owner_or_admin ON core.users
    FOR DELETE TO authenticated
    USING (
        (auth_user_id = auth.uid())
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')
    );

-- ── site.customers ─────────────────────────────────────────────────────────
-- Owner = the user who owns the customer record (customers.user_id), matched
-- through core.users with the existing `deleted_at IS NULL` predicate preserved.
DROP POLICY IF EXISTS customers_all_authenticated ON site.customers;

-- SELECT: owning user OR any staff (read access unchanged from the old policy).
CREATE POLICY customers_select_authenticated ON site.customers
    FOR SELECT TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.users p
            WHERE p.id = customers.user_id
              AND p.auth_user_id = auth.uid()
              AND p.deleted_at IS NULL
        )
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())
    );

-- INSERT: owning user OR admin staff.
CREATE POLICY customers_insert_owner_or_admin ON site.customers
    FOR INSERT TO authenticated
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM core.users p
            WHERE p.id = customers.user_id
              AND p.auth_user_id = auth.uid()
              AND p.deleted_at IS NULL
        )
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')
    );

-- UPDATE: owning user OR admin staff (USING + WITH CHECK both required).
CREATE POLICY customers_update_owner_or_admin ON site.customers
    FOR UPDATE TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.users p
            WHERE p.id = customers.user_id
              AND p.auth_user_id = auth.uid()
              AND p.deleted_at IS NULL
        )
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')
    )
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM core.users p
            WHERE p.id = customers.user_id
              AND p.auth_user_id = auth.uid()
              AND p.deleted_at IS NULL
        )
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')
    );

-- DELETE: owning user OR admin staff. Gated via USING — closes the support-role
-- DELETE escalation that a WITH-CHECK-only fix would have left open.
CREATE POLICY customers_delete_owner_or_admin ON site.customers
    FOR DELETE TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.users p
            WHERE p.id = customers.user_id
              AND p.auth_user_id = auth.uid()
              AND p.deleted_at IS NULL
        )
        OR EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')
    );

COMMIT;
