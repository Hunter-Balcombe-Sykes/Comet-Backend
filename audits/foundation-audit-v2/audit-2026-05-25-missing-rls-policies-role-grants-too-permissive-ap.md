`★ Insight ─────────────────────────────────────`
Three things confirmed during verification:
1. **All three analytics write paths** (pageviews, clicks, section views) go through the Laravel `AnalyticsController` — the `anon` INSERT policies on `site_visits`/`link_clicks` are defence-in-depth, NOT the primary write path. This changes the tier of RLS-2/RLS-3.
2. **Unsubscribes are fully server-side** (`PublicEmailUnsubscribeController` uses `app_backend` via Eloquent) — confirming the anon SELECT policy on email_subscriptions is dead weight AND dangerously over-broad.
3. **No explicit table-level `GRANT SELECT` to `anon`/`authenticated`** appears in the app migrations — but Supabase's hosted infrastructure applies schema-wide grants when a schema is added to the API list, making the over-broad anon SELECT policy live.
`─────────────────────────────────────────────────`

# RLS / Schema AuthZ Audit — 2026-05-25

**Branch:** development
**Lens:** missing RLS policies, role grants too permissive, app_backend privileges, schema-level authz gaps, unsafe seed data, Supabase project config
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260526010000_relax_waitlist_signups_constraints.sql
- supabase/migrations/20260526210000_b21_public_rls_validation_and_docs.sql
- supabase/migrations/20260526210001_create_feedback_table.sql
- supabase/migrations/20260526210002_feedback_hardening.sql
- supabase/seed.sql
- supabase/config.toml
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/PublicEmailUnsubscribeController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 4 complete

---

## P1 — Fix before pilot launch

- [ ] **#RLS-1** · P1 — `email_subs_public_unsubscribe` exposes entire subscriber list to the public internet
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:2093
    - **Affects:** Every email subscriber — their address, full name, subscription status, `consent_ip_hash`, `consent_source`, and `unsubscribe_token` are readable by any HTTP client using only the public anon key.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop the `email_subs_public_unsubscribe` policy entirely. The unsubscribe flow runs through `PublicEmailUnsubscribeController`, which queries via `app_backend` (BYPASSRLS) and has no need for a Supabase-side anon SELECT path.
        - Confirm in staging: `curl -H "apikey: <anon-key>" https://<ref>.supabase.co/rest/v1/email_subscriptions?select=email` should return 0 rows (blocked by GRANT or RLS), not the subscriber list.
        - If a future direct-SDK unsubscribe path is ever built, the replacement policy MUST filter to a specific token: `USING (unsubscribe_token = current_setting('request.jwt.claims.token', true))` or equivalent — never `IS NOT NULL`.
    - **Technical:** The USING clause `unsubscribe_token IS NOT NULL` evaluates to `TRUE` for every row in `notifications.email_subscriptions` because the column carries a `NOT NULL` constraint. PostgREST exposes all tables in schemas listed in `config.toml`'s `schemas = [...]` array (`notifications` is included); Supabase's hosted infrastructure applies table-level SELECT grants to `anon` for those schemas. Combined with the anon INSERT policy (`email_subs_public_insert`) that was explicitly designed and tightened in migration `20260526210000`, it is clear the anon role has active table-level privileges here. The result: `GET /rest/v1/email_subscriptions` with only the public anon key returns every subscriber row. The unsubscribe flow (verified in `PublicEmailUnsubscribeController`) is entirely server-side via `EmailSubscription::query()->where('unsubscribe_token', $token)` — app_backend bypasses RLS and needs no anon SELECT policy.
    - **Plain English:** Think of this like a public bulletin board where someone accidentally pinned the company's entire mailing list. Anyone who knows your Supabase project URL (which is embedded in the frontend) can read every subscriber's email address, name, and whether they've unsubscribed — without logging in. The "lock" on this room's door was set to "anyone can enter if the lights are on," and the lights are always on. The fix is to remove that lock rule entirely — the only process that actually needs to open this room is our own server, which has its own master key.
    - **Evidence:**
        ```sql
        -- Column has NOT NULL constraint — USING clause always evaluates TRUE:
        unsubscribe_token varchar(80) NOT NULL,

        -- Policy allows anon to SELECT every row in the table:
        CREATE POLICY email_subs_public_unsubscribe ON notifications.email_subscriptions
            FOR SELECT TO anon USING (unsubscribe_token IS NOT NULL);

        -- The actual unsubscribe flow never uses this policy:
        -- PublicEmailUnsubscribeController.php:21-24
        $sub = EmailSubscription::query()
            ->where('unsubscribe_token', $token)
            ->first();  -- app_backend; BYPASSRLS; no anon path used
        ```

---

## P2 — Should fix

- [ ] **#RLS-2** · P2 — `app_backend` holds `BYPASSRLS` with 28 tables carrying no explicit fallback policy
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql — Section 12 (role permissions), Section 10 (RLS policies)
    - **Affects:** Queue workers, webhooks, background jobs, and all Laravel API traffic. Every application query silently bypasses RLS; if `BYPASSRLS` is ever revoked — intentionally as hardening, or accidentally during a role reset — the application breaks across all 28 tables simultaneously with no warning.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - For each table without an explicit `app_backend` policy, add `FOR ALL TO app_backend USING (true) WITH CHECK (true)`. The ~8 tables that already have these policies (e.g., `core.professional_deletion_audit`, `core.feature_flags`, `site.enquiries`) serve as the template.
        - Prioritise `public.failed_jobs`, `public.job_batches`, `core.users`, and `site.sites` first — a failed queue or broken user lookup is immediately visible in production.
        - For append-only tables (`core.staff_audit_log`, `core.handle_change_log`, `core.auth_factor_events`), preserve the existing INSERT-only grant pattern rather than adding `FOR ALL`.
        - Only revoke `BYPASSRLS` after every table has a verified explicit policy and the test suite passes.
    - **Technical:** Section 12 documents this as "decision 16" with the caveat that revoking `BYPASSRLS` would break the app. That means RLS currently provides zero defence-in-depth against application-layer bugs on 28 tables: `core.users`, `core.partna_staff`, `core.customers`, `core.waitlist_signups`, `core.professional_confirmation_preferences`, `core.feedback`, `site.sites`, `site.blocks`, `site.site_media`, `site.media_variants`, `site.site_subdomain_aliases`, `site.professional_handle_aliases`, `site.themes`, `site.services`, `site.service_categories`, `notifications.notifications`, `notifications.notification_receipts`, `notifications.notification_email_preferences`, `notifications.notification_email_policies`, `notifications.email_subscriptions`, `analytics.site_visits`, `analytics.link_clicks`, `analytics.lead_submissions`, `analytics.section_views`, `analytics.site_metrics_daily`, `analytics.site_metrics_hourly`, `public.failed_jobs`, `public.job_batches`. The risk is not a current security bypass (BYPASSRLS is working as intended) but rather a fragile single point of control: one `ALTER ROLE app_backend NOBYPASSRLS` — in a runbook, a Supabase advisor recommendation, or an automated hardening script — silently breaks the application with no lint or CI signal.
    - **Plain English:** Right now the app uses a master key that bypasses all the individual door locks in the database. This works, but it means the locks aren't actually doing anything — they're just decoration. If someone ever removes the master key as a security improvement (a reasonable thing to want), all 28 doors become inaccessible overnight and the application goes dark. The fix is to cut individual keys for each door before retiring the master, so the locks become real.
    - **Evidence:**
        ```sql
        -- Section 12: BYPASSRLS explicitly granted, with inline acknowledgement of the gap:
        EXECUTE 'ALTER ROLE app_backend BYPASSRLS';
        -- app_backend is the Laravel service DB user; it bypasses RLS so queue
        -- workers, webhooks, and background jobs are never silently blocked
        -- (decision 16 — several KEEP RLS tables have no explicit app_backend
        -- policy, so revoking BYPASSRLS would default-deny them).

        -- Section 10: only ~8 tables have explicit app_backend policies, e.g.:
        CREATE POLICY professional_deletion_audit_app_backend_all
            ON core.professional_deletion_audit FOR ALL TO app_backend
            USING (true) WITH CHECK (true);

        -- No equivalent policy exists for core.users, site.sites, site.blocks,
        -- notifications.notifications, analytics.site_visits, public.failed_jobs,
        -- or the remaining 22 tables.
        ```

---

## P3 — Nice to have

- [ ] **#RLS-3** · P3 — `analytics.lead_submissions` intentional server-side-only write path is not documented in schema
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql — analytics.lead_submissions RLS block
    - **Affects:** Future developers who see sibling analytics tables (`site_visits`, `link_clicks`) carrying `FOR INSERT TO anon` policies and may add an equivalent to `lead_submissions`, inadvertently opening a write path that bypasses customer-creation validation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `COMMENT ON TABLE analytics.lead_submissions` explaining that writes are intentionally server-side only via `PublicCustomerLeadController` because lead submissions trigger customer creation and deduplication — operations that require app_backend.
        - Do NOT add an `anon` INSERT policy; doing so would bypass the customer FK resolution logic in the Laravel layer.
    - **Technical:** `analytics.site_visits` and `analytics.link_clicks` carry `FOR INSERT TO anon` policies as defence-in-depth for a potential future direct-SDK write path. `analytics.lead_submissions` is structurally different: it carries a FK to `core.customers` (`customer_id`) that is populated by `PublicCustomerLeadController`'s server-side customer-creation flow. An anon INSERT cannot satisfy this FK without the app layer, so the absence of the policy is correct. The risk is that a developer following the `site_visits` pattern would add one during a future analytics sprint, producing malformed rows with null `customer_id` that silently diverge from app-created rows.
    - **Plain English:** The visit and click tables have an "anyone can drop a note in the mailbox" rule — meant for direct browser writes in the future. The lead submissions table doesn't have this rule, and it shouldn't, because every lead needs the server to first create or find the customer record. The risk is a future developer seeing the mailbox rule on the other tables, adding it here too by analogy, and creating a hole that bypasses the customer creation logic. A one-line comment on the table prevents that mistake.
    - **Evidence:**
        ```sql
        -- analytics.site_visits HAS anon INSERT:
        CREATE POLICY site_visits_anyone_insert_valid_site ON analytics.site_visits
            FOR INSERT TO anon WITH CHECK (EXISTS (
                SELECT 1 FROM site.sites s WHERE s.id = site_visits.site_id ...

        -- analytics.lead_submissions has only SELECT policies + customer FK:
        CREATE POLICY lead_submissions_owner_select ON analytics.lead_submissions
            FOR SELECT TO authenticated ...
        CREATE POLICY lead_submissions_staff_select ON analytics.lead_submissions
            FOR SELECT TO authenticated ...
        -- FK requiring server-side resolution:
        CONSTRAINT lead_submissions_customer_fk FOREIGN KEY (customer_id)
            REFERENCES core.customers(id) ON DELETE SET NULL
        ```

- [ ] **#RLS-4** · P3 — `analytics.section_views` lacks an `anon` INSERT policy inconsistent with sibling analytics tables
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql — analytics.section_views RLS block
    - **Affects:** A future developer who adds a direct-SDK analytics path for `site_visits`/`link_clicks` but gets silent 403s on `section_views` and spends debugging time on the inconsistency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If the team intends `section_views` to follow the same defence-in-depth pattern as `site_visits` and `link_clicks`, add a validated `FOR INSERT TO anon` policy using the same published-site guard.
        - If section views are intentionally only ever written server-side (the current behaviour — confirmed in `AnalyticsController::sectionSeen()`), add `COMMENT ON TABLE analytics.section_views` documenting this. The existing `service_role` ALL policy suggests an Edge Function write path is planned, which would be consistent with eventually adding an anon path too.
    - **Technical:** `analytics.section_views` currently has three policies: `section_views_service_role_all` (FOR ALL TO service_role), `section_views_owner_select` (FOR SELECT TO authenticated), and `section_views_staff_select`. There is no `FOR INSERT TO anon` policy, unlike both `site_visits` and `link_clicks`. All three analytics tables are currently written via `AnalyticsController` (server-side Laravel), so the absence has no functional impact today. The inconsistency matters as a pattern-consistency signal: the `service_role` ALL policy suggests Edge Function writes are anticipated, at which point an anon INSERT policy becomes relevant.
    - **Plain English:** Two of three analytics tables have a "public mailbox" rule for future direct browser writes. The third one — section views — doesn't, even though it records the same kind of anonymous visitor data. The system works fine right now because everything goes through our server. This is a minor inconsistency that could trip up a developer later.
    - **Evidence:**
        ```sql
        -- analytics.section_views: three policies, none for anon INSERT:
        CREATE POLICY "section_views_service_role_all" ON analytics.section_views
            FOR ALL TO service_role USING (true) WITH CHECK (true);
        CREATE POLICY section_views_owner_select ON analytics.section_views
            FOR SELECT TO authenticated ...;
        CREATE POLICY section_views_staff_select ON analytics.section_views
            FOR SELECT TO authenticated ...;
        -- No FOR INSERT TO anon policy.

        -- Compare: analytics.site_visits has:
        CREATE POLICY site_visits_anyone_insert_valid_site ON analytics.site_visits
            FOR INSERT TO anon WITH CHECK (EXISTS (
                SELECT 1 FROM site.sites s WHERE s.id = site_visits.site_id
                AND s.professional_id = site_visits.professional_id AND s.is_published = true
            ));
        ```

- [ ] **#RLS-5** · P3 — `core.auth_factor_events` has no staff-readable policy — MFA audit log inaccessible via Supabase API
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql — core.auth_factor_events RLS block
    - **Affects:** Staff/admin ability to query the MFA enrollment and verification audit log directly from the Supabase dashboard SQL editor or client SDK when diagnosing a suspected account takeover.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `FOR SELECT TO authenticated` policy mirroring the `handle_change_log_staff_select` pattern: allow `core.partna_staff` members with `role IN ('admin', 'support')` to read.
        - Keep the existing `service_role` INSERT/SELECT policies — Supabase Auth hooks are the only writers.
    - **Technical:** `core.auth_factor_events` has only two policies targeting `service_role`: INSERT and SELECT. The `authenticated` role has no access. Staff access via the Supabase SDK or dashboard SQL editor is therefore blocked — they must go through the Laravel staff API (which uses app_backend with BYPASSRLS). This is inconsistent with `core.staff_audit_log` and `core.handle_change_log`, both of which carry explicit staff SELECT policies allowing direct queries. For a table that tracks MFA `verify_failed` and `verify_rejected_by_hook` events — the primary signal for brute-force detection — the inability to query it directly from the Supabase dashboard adds friction during incident response.
    - **Plain English:** The MFA security log records every failed two-factor authentication attempt — exactly what you'd want to check when suspecting an account takeover. Every other audit table in the system lets our support team look at it directly. This one doesn't; they have to go through a separate tool. It's a minor operational inconvenience that matters most during an incident when speed counts.
    - **Evidence:**
        ```sql
        -- core.auth_factor_events: only service_role can access:
        CREATE POLICY "service role inserts" ON core.auth_factor_events
            FOR INSERT TO service_role WITH CHECK (true);
        CREATE POLICY "service role reads" ON core.auth_factor_events
            FOR SELECT TO service_role USING (true);
        -- No policy for TO authenticated.

        -- Compare: core.handle_change_log has staff read access:
        CREATE POLICY handle_change_log_staff_select ON core.handle_change_log
            FOR SELECT TO authenticated
            USING (EXISTS (SELECT 1 FROM core.partna_staff ps
                WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')));
        ```

- [ ] **#RLS-6** · P3 — Password policy set to `lower_upper_letters_digits`; symbol requirement not enforced
    - **Where:** supabase/config.toml:114
    - **Affects:** All new and future user accounts — marginally smaller password search space compared to requiring symbols.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `password_requirements` from `"lower_upper_letters_digits"` to `"lower_upper_letters_digits_symbols"`.
        - Coordinate with the frontend team to update the password-strength indicator so users aren't surprised by a rejected submission.
        - No migration needed — Supabase enforces requirements only on creation and change; existing accounts are grandfathered until their next password update.
    - **Technical:** Commit `9fcbcbae` (#SEC-5) deliberately chose `lower_upper_letters_digits` over the stronger `lower_upper_letters_digits_symbols` tier. That was a reasonable UX tradeoff for the initial implementation. The step up to symbols expands the effective alphabet from ~62 characters to ~95 per position, increasing per-password entropy by roughly 7 bits at the 8-character minimum — meaningful resistance against offline dictionary attacks if a breach occurs. The change is a single config line with no rollback risk.
    - **Plain English:** Passwords currently need upper and lower case letters plus numbers. Adding a symbol requirement — like `!` or `#` — makes passwords harder to guess, roughly equivalent to adding an extra digit to a combination lock. It's a one-line config change and only affects new passwords; nobody's existing account gets locked out.
    - **Evidence:**
        ```toml
        # supabase/config.toml line ~114
        # Supported values are: `letters_digits`, `lower_upper_letters_digits`,
        # `lower_upper_letters_digits_symbols`
        password_requirements = "lower_upper_letters_digits"
        ```
