# Schema RLS Audit — 2026-05-20

**Branch:** development
**Lens:** schema RLS row level security policies
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `supabase/migrations/20260403000000_v2_baseline.sql`
- `supabase/migrations/20260407000000_billing_stripe_integration.sql`
- `supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql`
- `supabase/migrations/20260422040000_create_site_enquiries.sql`
- `supabase/migrations/20260423000001_create_gdpr_requests.sql`
- `supabase/migrations/20260425000002_create_data_export_audit.sql`
- `supabase/migrations/20260428010000_create_analytics_cart_events.sql`
- `supabase/migrations/20260508100000_url_columns_and_triggers.sql`
- `supabase/migrations/20260508400000_rename_sidest_staff_to_partna_staff.sql`
- `supabase/migrations/20260513200000_harden_audit_tables.sql`
- `supabase/migrations/20260514200000_email_send_sentinels.sql`
- `supabase/migrations/20260515100000_create_analytics_section_views.sql`
- `supabase/migrations/20260519100000_handle_alias_lifecycle.sql`
- `supabase/migrations/20260519200000_create_commerce_commission_export_audit.sql`
- `supabase/migrations/20260521000000_add_account_type_column_and_backfill.sql`
- `supabase/migrations/20260522000001_create_brand_signup_code_audit.sql`
- `supabase/migrations/202605180000000_create_feature_flags.sql`

**Dropped:** RLS-2 (renamed-table policy OID claim is factually incorrect — PostgreSQL stores policy expressions as pg_node_tree using OIDs, not text strings; `ALTER TABLE ... RENAME` preserves all OIDs so policy expressions remain valid after rename; commit `20260508400000` comment is correct).

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 7 complete

---

## P2 — Should fix

- [ ] **#RLS-1** · P2 — `site.public_site_payload` view returns zero rows for anonymous Supabase REST callers
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (view definition, `core.professionals` RLS, `site.services` RLS)
    - **Affects:** Any consumer that queries `site.public_site_payload` via Supabase anon key (e.g. future Astro SSR path, Supabase Studio preview, integration tests using the anon client). The Laravel app_backend path (BYPASSRLS) is unaffected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CREATE POLICY professionals_anon_select ON core.professionals FOR SELECT TO anon USING (status = 'active' AND deleted_at IS NULL)` — the view's own `WHERE` already restricts to published sites, so this minimal grant is sufficient.
        - Add `CREATE POLICY services_anon_select ON site.services FOR SELECT TO anon USING (is_active = true AND deleted_at IS NULL)` — services subquery inside the view needs anon SELECT.
        - Verify the remaining underlying tables already have anon policies: `site.sites` (`sites_public_read_published`), `site.blocks` (`link_blocks_public_read_active_published`), `site.themes` (`themes_public_read`), `site.site_media` (`site_media_public_read_published`), `site.media_variants` (`media_variants_public_read`), `site.service_categories` (`service_categories_public_read`) — confirmed present.
    - **Technical:** The view is defined with `WITH (security_invoker='on')`, meaning Postgres evaluates each underlying table access using the caller's privileges. `core.professionals` has only `TO authenticated` in `professionals_all_authenticated`; `site.services` has only `services_pro_all` and `services_staff_all` (both `TO authenticated`). When an `anon` caller queries the view, the JOIN on `core.professionals` sees zero rows (RLS default-deny for anon = no matching policy = empty result set), so the view silently returns zero rows rather than a hard error. The public storefront currently works correctly because it routes through the Laravel API (app_backend, BYPASSRLS). This is an oversight given the clear design intent — six other underlying tables have explicit anon SELECT policies, and the view filters to `is_published = true AND p.status = 'active'` anyway.
    - **Plain English:** The public part of the website is supposed to be readable by anyone without logging in. The database's access rules do allow this for most of the data, but two tables — the one holding professional profile info and the one holding services — are accidentally locked to "logged-in users only." When the main backend system fetches the data, it has a master key that bypasses these locks, so the site works today. But if anything ever tried to read the data the "normal anonymous" way (like future server-rendered pages or automated tests), it would get back an empty result and the site would appear to have no content at all.
    - **Evidence:**
        ```sql
        -- View defined with security_invoker='on' (baseline, line ~1200):
        CREATE OR REPLACE VIEW site.public_site_payload
        WITH (security_invoker='on') AS
        SELECT ... FROM site.sites s
        JOIN core.professionals p ON p.id = s.professional_id   -- no anon policy
        ...
        FROM site.services sv ...                               -- no anon policy

        -- core.professionals: only authenticated policy
        CREATE POLICY professionals_all_authenticated ON core.professionals TO authenticated
            USING ((auth_user_id = auth.uid()) OR (...));

        -- site.services: only authenticated policies
        CREATE POLICY services_pro_all ON site.services TO authenticated ...;
        CREATE POLICY services_staff_all ON site.services TO authenticated ...;
        -- (no anon policy on either table)
        ```

- [ ] **#RLS-2** · P2 — `core.feature_flags` and `core.feature_flag_overrides` have no RLS enabled
    - **Where:** `supabase/migrations/202605180000000_create_feature_flags.sql`
    - **Affects:** Consistency and future-proofing. Without RLS, a future migration that adds a broad table-level grant to `authenticated` (e.g. a schema-wide default privileges change) would immediately expose feature flags to all authenticated professionals with no row-level fence. The `service_role` key (Supabase Studio / admin tooling) bypasses RLS and can see all rows today regardless.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.feature_flags ENABLE ROW LEVEL SECURITY;`
        - `ALTER TABLE core.feature_flag_overrides ENABLE ROW LEVEL SECURITY;`
        - Add `app_backend` all-rows policies (`FOR ALL TO app_backend USING (true)`), matching the pattern used by `core.gdpr_requests` and `core.data_export_audit`.
        - Add staff-only SELECT policies using `EXISTS (SELECT 1 FROM core.partna_staff WHERE auth_user_id = auth.uid() AND role IN ('admin','support'))`.
        - No policy for professionals — feature flag configuration is platform-internal.
    - **Technical:** The tables were created without `ENABLE ROW LEVEL SECURITY`. Currently, `authenticated` professionals cannot reach them because there is no explicit `GRANT SELECT ... TO authenticated` on these tables (schema USAGE alone is insufficient). However, the `service_role` bypasses RLS by default, and any future broad privilege grant would open the tables. This departs from the project convention that every tenant-adjacent table has RLS enabled with explicit policies — a convention already applied to every other table in this migration wave.
    - **Plain English:** These tables act as the control panel for experimental features — toggling them on or off for specific brands or users. Currently only the automated backend can see them, but there's no database-level lock on the door. If anyone ever gives more users keys to the building (a routine infrastructure change), they'd have full access to flip the switches. Adding a lock now is cheap insurance.
    - **Evidence:**
        ```sql
        CREATE TABLE core.feature_flags (
            key text PRIMARY KEY,
            ...
        );
        -- No ENABLE ROW LEVEL SECURITY
        -- No CREATE POLICY

        CREATE TABLE core.feature_flag_overrides (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            flag_key text NOT NULL REFERENCES core.feature_flags(key) ON DELETE CASCADE,
            ...
        );
        -- No ENABLE ROW LEVEL SECURITY
        -- No CREATE POLICY
        ```

- [ ] **#RLS-3** · P2 — `brand.signup_code_audit` has no RLS enabled
    - **Where:** `supabase/migrations/20260522000001_create_brand_signup_code_audit.sql`
    - **Affects:** Signup-code claim history (who claimed which brand's code, from which IP) is unprotected at the database layer. No broad grant to `authenticated` exists today, but the pattern is inconsistent with every other audit table in the codebase and is one privilege grant away from leaking claim history cross-brand.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE brand.signup_code_audit ENABLE ROW LEVEL SECURITY;`
        - Add `app_backend` all-rows policy.
        - Add staff SELECT policy (admin/support).
        - Add brand-owner SELECT policy scoped to `brand_profile_id = (SELECT id FROM brand.brand_profiles WHERE professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid()))`.
    - **Technical:** The create migration contains only `CREATE TABLE` and `CREATE INDEX` — no `ENABLE ROW LEVEL SECURITY` or `CREATE POLICY`. This is inconsistent with the convention established by `20260513200000_harden_audit_tables.sql` which explicitly hardened `professional_deletion_audit`, `wallet_currency_switch_audit`, and `brand_status_history` for exactly this gap. `signup_code_audit` was created after that sweep and missed it.
    - **Plain English:** This is a logbook of every time someone used an invitation code to join a brand's partner programme — including the claimer's identity and IP address. The database has no lock on this logbook. Today only the automated backend can reach it, but best practice requires an explicit lock so that doesn't change accidentally.
    - **Evidence:**
        ```sql
        CREATE TABLE brand.signup_code_audit (
          id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          brand_profile_id      uuid NOT NULL REFERENCES brand.brand_profiles(id),
          event                 text NOT NULL CHECK (event IN ('generated','rotated',
                                    'deactivated','reactivated','claimed','failed_claim')),
          ...
          joined_professional_id uuid,
          created_at            timestamptz NOT NULL DEFAULT now()
        );
        -- No ALTER TABLE ... ENABLE ROW LEVEL SECURITY
        -- No CREATE POLICY
        ```

- [ ] **#RLS-4** · P2 — `commerce.commission_export_audit` has no RLS enabled
    - **Where:** `supabase/migrations/20260519200000_create_commerce_commission_export_audit.sql`
    - **Affects:** Commission export request metadata (who requested, what filters, file SHA256, recipient email) is unprotected at the database layer. Same caveat as RLS-3: no broad `authenticated` grant today, but the gap is inconsistent with the project convention and the table contains financial PII (recipient emails, export scopes).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE commerce.commission_export_audit ENABLE ROW LEVEL SECURITY;`
        - Add `app_backend` all-rows policy.
        - Add staff SELECT policy.
        - Add tenant SELECT policy: professionals can see their own export requests (`professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)`).
    - **Technical:** The table's separate index migration (`20260519200001`) follows conventions but the table creation itself omits RLS. Contrast with `core.data_export_audit` from `20260425000002` which sets `ALTER TABLE core.data_export_audit ENABLE ROW LEVEL SECURITY` and `data_export_audit_app_backend_all` immediately — the `commission_export_audit` was modelled on the same concept but omitted the security step.
    - **Plain English:** This table records every time someone asked to export their commission data — what they filtered, where it was sent, and a fingerprint of the file. It's financial paperwork that should only be visible to the person who requested it and to the support team. Currently there's no database-level rule enforcing that.
    - **Evidence:**
        ```sql
        CREATE TABLE commerce.commission_export_audit (
            id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id             uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
            ...
            recipient_email             text NOT NULL,
            file_sha256                 text,
            ...
        );
        -- No ALTER TABLE ... ENABLE ROW LEVEL SECURITY
        -- No CREATE POLICY
        ```

## P3 — Nice to have

- [ ] **#RLS-5** · P3 — `analytics.lead_submissions` has RLS enabled but no policies; Supabase REST access by authenticated users is silently denied
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (section 13)
    - **Affects:** Staff inspecting lead submissions via Supabase Studio or direct REST calls receive zero rows. The Laravel backend (app_backend, BYPASSRLS) is unaffected.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add a staff SELECT policy using `EXISTS (SELECT 1 FROM core.partna_staff WHERE auth_user_id = auth.uid())`. Anon INSERT policy is optional (leads are written server-side, not by the browser directly).
    - **Technical:** `ALTER TABLE analytics.lead_submissions ENABLE ROW LEVEL SECURITY` appears in the baseline with no subsequent `CREATE POLICY`. Default-deny. The `anon` insert policy on `analytics.link_clicks` and `analytics.site_visits` shows the baseline author intended to wire these tables — `lead_submissions` was missed.
    - **Plain English:** A filing cabinet for web enquiry events exists and is locked, but nobody was given a key — including support staff who might want to browse it directly in the database dashboard.
    - **Evidence:**
        ```sql
        -- analytics.lead_submissions
        ALTER TABLE analytics.lead_submissions ENABLE ROW LEVEL SECURITY;
        -- (no CREATE POLICY follows in any migration)
        ```

- [ ] **#RLS-6** · P3 — `site.enquiries` has only an `app_backend` policy; owning professional cannot read their enquiries via Supabase REST
    - **Where:** `supabase/migrations/20260422040000_create_site_enquiries.sql`
    - **Affects:** Any future consumer querying enquiries with the authenticated Supabase key. Backend enquiry reads via the Laravel API are unaffected.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add professional owner SELECT policy and staff SELECT policy, matching the `site.blocks` pattern the migration comment references.
    - **Technical:** The migration comment says "gate reads/writes to the owning professional, same pattern as site.blocks" but only implements `enquiries_app_backend_all`. The `app_backend` policy is redundant (app_backend has BYPASSRLS). The intended authenticated policies were never created.
    - **Plain English:** The support inbox for each professional was set up with only the automated backend having a key, even though the comment says it was supposed to match the same design as other locked cabinets that do give owners their own key.
    - **Evidence:**
        ```sql
        CREATE POLICY enquiries_app_backend_all
            ON site.enquiries
            FOR ALL
            TO app_backend
            USING (true)
            WITH CHECK (true);
        -- No policy for TO authenticated
        ```

- [ ] **#RLS-7** · P3 — `site.professional_handle_aliases` and `core.handle_change_log` have RLS enabled but no policies for authenticated users
    - **Where:** `supabase/migrations/20260508100000_url_columns_and_triggers.sql` (aliases); `supabase/migrations/20260519100000_handle_alias_lifecycle.sql` (handle_change_log)
    - **Affects:** Staff and professionals cannot inspect alias history or handle-change audit rows via Supabase REST.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `site.professional_handle_aliases`: add staff SELECT policy; optionally add professional owner SELECT scoped by `professional_id`.
        - `core.handle_change_log`: add staff SELECT policy. The table already has `GRANT INSERT, SELECT TO app_backend` — no authenticated policies were ever created.
    - **Technical:** Both tables enable RLS with no policies, producing default-deny for all non-BYPASSRLS roles. The handle_change_log migration explicitly marks the table as an append-only 7-year audit log, yet no read policy exists for staff who would need to review rename history during a dispute.
    - **Plain English:** The history log of name changes (like a name-change register at a registry office) is locked with no one given a key except the automated backend — support staff can't review it directly if a dispute arises about who owned a handle and when.
    - **Evidence:**
        ```sql
        -- site.professional_handle_aliases (20260508100000)
        ALTER TABLE site.professional_handle_aliases ENABLE ROW LEVEL SECURITY;
        -- no CREATE POLICY

        -- core.handle_change_log (20260519100000)
        ALTER TABLE core.handle_change_log ENABLE ROW LEVEL SECURITY;
        GRANT INSERT, SELECT ON core.handle_change_log TO app_backend;
        -- no CREATE POLICY for authenticated
        ```

- [ ] **#RLS-8** · P3 — `core.gdpr_requests` and `core.data_export_audit` have only `app_backend` policies; staff cannot inspect them via Supabase REST
    - **Where:** `supabase/migrations/20260423000001_create_gdpr_requests.sql`; `supabase/migrations/20260425000002_create_data_export_audit.sql`
    - **Affects:** Staff querying GDPR request status or data export audit history via Supabase Studio get zero rows. Backend operations are unaffected.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add staff SELECT policy to both tables: `EXISTS (SELECT 1 FROM core.partna_staff WHERE auth_user_id = auth.uid())`.
    - **Technical:** Both migrations follow the same pattern: RLS enabled, `app_backend_all` policy, explicit `GRANT ... TO app_backend`. The `professional_deletion_audit` table received a staff SELECT policy in `20260513200000_harden_audit_tables.sql` for exactly this reason — `gdpr_requests` and `data_export_audit` predate that sweep and were not included.
    - **Plain English:** The log of customer data requests and the log of data export jobs are both locked so only the automated system can see them. The support team must rely on the web app to view them — they can't open the cabinet directly in the database dashboard.
    - **Evidence:**
        ```sql
        CREATE POLICY gdpr_requests_app_backend_all
            ON core.gdpr_requests
            FOR ALL TO app_backend
            USING (true) WITH CHECK (true);
        -- no policy for TO authenticated

        CREATE POLICY data_export_audit_app_backend_all
            ON core.data_export_audit
            FOR ALL TO app_backend
            USING (true) WITH CHECK (true);
        -- no policy for TO authenticated
        ```

- [ ] **#RLS-9** · P3 — `billing.webhook_events` has RLS enabled but no policies
    - **Where:** `supabase/migrations/20260407000000_billing_stripe_integration.sql`
    - **Affects:** Staff cannot query webhook receipts via Supabase REST. Backend processing via app_backend (BYPASSRLS) is unaffected. Stripe idempotency works correctly.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add staff SELECT policy and `app_backend` all-rows policy for completeness.
    - **Technical:** `ALTER TABLE billing.webhook_events ENABLE ROW LEVEL SECURITY` appears immediately after the table creation with no subsequent policy. The table is internal infrastructure; no professional-facing policy is needed — staff SELECT is sufficient.
    - **Plain English:** The receipt book for incoming Stripe and Shopify webhook deliveries is locked with no keys issued. Support staff debugging a missed payment can't look at it directly.
    - **Evidence:**
        ```sql
        ALTER TABLE billing.webhook_events ENABLE ROW LEVEL SECURITY;
        -- no CREATE POLICY follows in any migration
        ```

- [ ] **#RLS-10** · P3 — `analytics.cart_events` and `analytics.section_views` restrict to `service_role` only; staff cannot read via authenticated REST
    - **Where:** `supabase/migrations/20260428010000_create_analytics_cart_events.sql`; `supabase/migrations/20260515100000_create_analytics_section_views.sql`
    - **Affects:** Staff reading cart-funnel or section-view analytics via Supabase client with the `authenticated` role get zero rows. Other analytics tables (`site_visits`, `link_clicks`, `booking_events`) all have staff SELECT policies — these two are outliers.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `CREATE POLICY <table>_staff_select ON analytics.<table> FOR SELECT TO authenticated USING (EXISTS (SELECT 1 FROM core.partna_staff WHERE auth_user_id = auth.uid()))` to both tables.
    - **Technical:** Both tables use `FOR ALL TO service_role USING (true)` as their only policy, matching each other but not the rest of the analytics schema. The `service_role` policy means even staff using the `authenticated` role (the norm for Supabase JS client calls in a staff context) are blocked. App_backend reads via BYPASSRLS and is unaffected.
    - **Plain English:** Two of the monitoring screens — the one showing which products got added to carts and the one showing which sections visitors scrolled to — are only visible to the automated system, not to support staff even though equivalent screens for page visits and link clicks are fully accessible to staff.
    - **Evidence:**
        ```sql
        CREATE POLICY "cart_events_service_role_all"
            ON analytics.cart_events
            FOR ALL TO service_role
            USING (true) WITH CHECK (true);
        -- no policy for TO authenticated

        CREATE POLICY "section_views_service_role_all"
            ON analytics.section_views
            FOR ALL TO service_role
            USING (true) WITH CHECK (true);
        -- no policy for TO authenticated
        ```

- [ ] **#RLS-11** · P3 — `notifications.broadcast_email_receipts` has no RLS enabled
    - **Where:** `supabase/migrations/20260514200000_email_send_sentinels.sql`
    - **Affects:** No immediate exposure (no `authenticated` table grant exists), but the table is inconsistent with the project convention. The `notifications.*` schema has RLS on every other table.
    - **Effort:** S (~0.5–1h)
    - **What to do:** `ALTER TABLE notifications.broadcast_email_receipts ENABLE ROW LEVEL SECURITY;`. Add `app_backend` all-rows policy. Add staff SELECT policy for debugging duplicate-send investigations.
    - **Technical:** The migration only contains `CREATE TABLE` — no RLS directive. The sibling tables in the same migration (`notifications.notifications` — already had RLS from baseline; `site.enquiries` — RLS enabled in its own migration) both have RLS. This is a pattern break within a single migration file.
    - **Plain English:** A checklist used to track which announcement emails were delivered to which subscribers sits without a lock. Only the automated system writes to it, but there's no database-level rule ensuring that stays true.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS notifications.broadcast_email_receipts (
            notification_id uuid NOT NULL REFERENCES notifications.notifications(id) ON DELETE CASCADE,
            subscription_id uuid NOT NULL,
            email_sent_at   timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (notification_id, subscription_id)
        );
        -- No ALTER TABLE ... ENABLE ROW LEVEL SECURITY
        -- No CREATE POLICY
        ```

`★ Insight ─────────────────────────────────────`
- PostgreSQL stores RLS policy expressions as `pg_node_tree` (parsed expression ASTs referencing table OIDs), not as raw text strings. This means `ALTER TABLE ... RENAME` is transparent to all dependent policies — DeepSeek's RLS-2 finding rested on the incorrect assumption that expressions are stored and re-parsed as strings. The migration author's comment "Postgres updates RLS policies … automatically via OID" was correct.
- When `ENABLE ROW LEVEL SECURITY` is set with no matching policy, the default behaviour is **zero rows returned** (not an error) for non-superuser roles. This matters for consumer impact assessment: a missing anon policy on a view's underlying table silently produces empty results rather than a 500, making the regression harder to detect in testing.
- The most durable RLS pattern in this codebase uses three layers for append-only audit tables: (1) split INSERT/SELECT policies rather than `FOR ALL`, (2) explicit `REVOKE UPDATE, DELETE`, and (3) a BEFORE UPDATE OR DELETE trigger that raises unconditionally — visible in `core.staff_audit_log` (migration `20260517300000`). Tables added after this pattern was established should adopt all three layers, not just the policy.
`─────────────────────────────────────────────────`
