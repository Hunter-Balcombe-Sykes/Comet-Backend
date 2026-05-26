`★ Insight ─────────────────────────────────────`
- `security_invoker='on'` on the `public_site_payload` view would block anonymous callers from the `core.users` JOIN — but it's only ever queried by `app_backend` through the Laravel service layer, so the missing anon policy on `core.users` is not a gap.
- `section_views` not having an anon INSERT policy is intentional: `AnalyticsController` is the write path, not direct PostgREST, so RLS-2 (DeepSeek's "silent data loss") is a false positive — the controller exists at `app/Http/Controllers/Api/PublicSite/AnalyticsController.php:250`.
`─────────────────────────────────────────────────`

# Schema / RLS / Role Permissions Audit — 2026-05-24

**Branch:** development
**Lens:** missing RLS policies, role grants too permissive, app_backend privileges, schema-level authz gaps, unsafe seed data, Supabase project config
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/seed.sql
- supabase/config.toml

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **RLS-1** · P1 — `app_backend` role granted `BYPASSRLS` removes the database as a second line of authorization defense
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (Section 12 — ROLE PERMISSIONS)
    - **Affects:** All tenant data across every schema. A bug in any single Laravel Policy class, or a raw query injection, grants unrestricted access to every user's PII with no database-layer safety net.
    - **Effort:** XL (~16–32h)
    - **What to do:**
        - Audit every table that currently lacks an explicit `app_backend` RLS policy. The migration comment acknowledges this directly: "several KEEP RLS tables have no explicit app_backend policy, so revoking BYPASSRLS would default-deny them."
        - Add `app_backend` policies to the remaining uncovered tables. The pattern already established in the migration (e.g. `enquiries_app_backend_all`, `feature_flags_app_backend_all`) is the template — `FOR ALL TO app_backend USING (true) WITH CHECK (true)`.
        - Once every table that app_backend legitimately touches has an explicit policy, remove `BYPASSRLS` from the role.
        - Add integration tests that verify RLS blocks `app_backend` on tables where it should not have access (e.g., `core.partna_staff` writes from a non-admin context).
    - **Technical:** The migration explicitly executes `ALTER ROLE app_backend BYPASSRLS` with a comment documenting why: several tables lack explicit `app_backend` policies and would default-deny without the bypass. This makes RLS a no-op for the entire Laravel runtime. The application's Policy classes are currently the sole enforcement layer. A single missing `authorizeForUser` call, a Policy that fails open, or a raw `DB::raw` query reachable through any code path would expose all rows in all schemas with no database-layer fallback. The fix is incremental: audit which tables `app_backend` actually needs to touch, add explicit policies for them, then drop the bypass. The append-only hardening already done for `staff_audit_log`, `handle_change_log`, and `auth_factor_events` (Section 12 `REVOKE UPDATE, DELETE`) demonstrates the project already understands this pattern — it just needs to be completed for the remaining tables.
    - **Plain English:** Think of the database as a filing cabinet where each drawer has its own lock. The way it's set up right now, the application has a master key that opens every drawer — even ones it should never touch. The master key was given because a few drawers don't have proper locks installed yet. The right fix is to install the missing locks, then take back the master key. Until then, any mistake in the application's own access-control logic means an attacker (or a bug) can read every customer's personal information. It's the last remaining "all-or-nothing" risk in the system.
    - **Evidence:**
        ```sql
        -- app_backend is the Laravel service DB user; it bypasses RLS so queue
        -- workers, webhooks, and background jobs are never silently blocked
        -- (decision 16 — several KEEP RLS tables have no explicit app_backend
        -- policy, so revoking BYPASSRLS would default-deny them).
        EXECUTE 'ALTER ROLE app_backend BYPASSRLS';
        ```

---

## P2 — Should fix

- [ ] **RLS-2** · P2 — Public INSERT policies on `waitlist_signups` and `email_subscriptions` have no input validation at the database layer
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (RLS sections for `core.waitlist_signups` and `notifications.email_subscriptions`)
    - **Affects:** Data quality and storage costs. Both tables accept unrestricted anonymous inserts from any caller — a script can flood either with junk rows including invalid emails, empty names, or oversized payloads.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `WITH CHECK` predicate to `waitlist_public_insert` that validates a non-empty name and a plausible email format, e.g. `email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' AND length(name) > 0`.
        - Add the same email-format check to `email_subs_public_insert`.
        - Note: `config.toml` has `[auth.captcha]` entirely commented out — there is no bot-protection at the Supabase Auth edge to compensate. If these endpoints are fronted by the Laravel backend (which can apply rate limiting), document that; otherwise the PostgREST path is unguarded.
    - **Technical:** Both policies use `WITH CHECK (true)`, meaning any anonymous PostgREST request can insert arbitrary rows — blank names, invalid email strings, or strings at the column's maximum length. `waitlist_signups` has a `UNIQUE` index on `email_lc`, which limits duplicate abuse, but does not prevent flooding with unique-looking garbage addresses. `email_subscriptions` has a `UNIQUE` index on `(list_key, email_lc)` and `(professional_id, list_key, email_lc)`, offering similar partial protection. A simple regex in the `WITH CHECK` clause aligns the RLS policy with the table's own `NOT NULL` and `CHECK` constraints as a low-cost hardening measure.
    - **Plain English:** There's a signup sheet and a mailing list clipboard on the counter, each with a pen and no instructions. Anyone can walk up and write anything — fake names, nonsense email addresses, or the same entry thousands of times with slightly different spellings. A bot could fill both with garbage in seconds, drowning real signups in noise. Adding a basic check that the email looks like an actual email address (has an `@` and a dot after it) costs almost nothing and eliminates the dumbest abuse.
    - **Evidence:**
        ```sql
        CREATE POLICY waitlist_public_insert ON core.waitlist_signups
            FOR INSERT TO anon WITH CHECK (true);

        CREATE POLICY email_subs_public_insert ON notifications.email_subscriptions FOR INSERT TO anon WITH CHECK (true);
        ```

---

## P3 — Nice to have

- [ ] **RLS-3** · P3 — `seed.sql` is entirely dead code — the enterprise guard fires immediately and skips every insert
    - **Where:** supabase/seed.sql (lines 1–15)
    - **Affects:** Local development and CI environments. Running `supabase db reset` leaves every schema empty — no sample user, no site, no blocks, no services. Any frontend or integration work against local Supabase sees a blank database.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `seed.sql` with inserts targeting the surviving tables: `core.users`, `site.sites`, `site.blocks`, `site.site_media`, `site.services`, `site.service_categories`, `notifications.notifications`, etc.
        - The guard block checking for `core.enterprises` can be removed entirely — that table was dropped in the baseline.
        - Add at least one `active` + `is_published = true` user and site so that `site.public_site_payload` returns a row and the public site rendering can be exercised locally.
    - **Technical:** The seed file opens with a `DO $$` block that checks for `core.enterprises`. The baseline migration (`20260526000000_baseline_standalone_user.sql`) creates only `core.users` — `core.enterprises` does not exist. The guard fires, prints a `RAISE NOTICE`, and returns immediately. Every `INSERT` below — into `core.enterprises`, `core.professionals`, `core.professional_enterprise_memberships`, `retail.enterprise_shopify_accounts`, `retail.enterprise_brands`, `retail.enterprise_products`, `retail.professional_selections` — is unreachable. All of those tables and schemas were dropped in the strip. The strip commits (`5e92aac6`, `821aff26`) that consolidated the baseline did not update the seed file.
    - **Plain English:** The seed file is like a recipe book that opens with "if you don't have a commercial oven, skip everything." The kitchen was renovated and the commercial oven was removed, so the book skips every single recipe. Anyone setting up the project locally gets an empty kitchen with nothing to cook. The recipe book needs to be rewritten for the new kitchen.
    - **Evidence:**
        ```sql
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = 'core'
                  AND table_name = 'enterprises'
            ) THEN
                RAISE NOTICE 'Skipping enterprise seed scenarios: core.enterprises is missing.';
                RETURN;
            END IF;
        ```

- [ ] **RLS-4** · P3 — `config.toml` lists dropped schemas `billing` and `retail` in `api.schemas` and `extra_search_path`
    - **Where:** supabase/config.toml (line `schemas = [...]` and `extra_search_path = [...]` under `[api]`)
    - **Affects:** Local dev only — no runtime error since PostgREST silently ignores missing schemas. Risk is forward-looking: a future `billing` or `retail` schema created for v2 would be auto-exposed via PostgREST on local dev before any RLS is in place.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `"billing"` and `"retail"` from both `schemas` and `extra_search_path`.
        - Evaluate whether `"site"` and `"notifications"` should be added to `api.schemas`. Both schemas exist in the baseline and have RLS policies targeting `anon` and `authenticated`, but are currently not listed — meaning those policies are unreachable via PostgREST directly (all access goes through the Laravel backend). If direct PostgREST access is never intended, add a comment stating that.
    - **Technical:** The strip commits (`5e92aac6`) dropped `billing` and `retail` schemas entirely from the baseline migration. `config.toml` was not updated in parallel. PostgREST logs a warning for non-existent schemas but continues, so there is no runtime failure. The actual risk is schema exposure on accidental recreation: if a future migration introduces a `billing` schema for v2 payments without updating `config.toml`, its tables would be exposed via PostgREST on the dev stack immediately — before RLS is configured — potentially leaking data during local integration testing. `site` and `notifications` schemas are not listed, meaning their anon-targeted RLS policies (`sites_public_read_published`, `email_subs_public_insert`, etc.) are only reachable via the Laravel backend's `app_backend` connection, not via direct PostgREST calls. Whether that is intended should be documented.
    - **Plain English:** The project's API directory still lists two departments that were demolished during the renovation. The directory is out of date. While the missing rooms don't cause a crash, the stale listing is a trap: if someone builds a new "Billing" room in the future, it gets automatically added to the public directory before they've installed any locks. The directory should be updated to list only the rooms that actually exist.
    - **Evidence:**
        ```toml
        schemas = ["public", "graphql_public", "core", "analytics", "billing", "retail"]
        extra_search_path = ["public", "extensions", "core", "analytics", "billing", "retail"]
        ```

- [ ] **RLS-5** · P3 — `site.site_media` DELETE policy is staff-only — owner hard-delete is silently blocked with no documentation
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (RLS section for `site.site_media`)
    - **Affects:** Any future developer who adds a hard-delete path for media. The policy asymmetry (owner can INSERT/UPDATE but not DELETE) will produce a cryptic RLS row rejection with no clear error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If the application exclusively soft-deletes media (setting `deleted_at` via UPDATE, which is covered by `site_media_update_authenticated`), add a comment to the DELETE policy documenting this: `-- Hard-delete is staff-only. App uses soft-delete (UPDATE deleted_at); owners never issue real DELETEs.`
        - If hard-delete is a planned feature for owners, add an owner DELETE policy matching the INSERT/UPDATE pattern (checking `site.sites` → `core.users` → `auth.uid()`).
    - **Technical:** The SELECT, INSERT, and UPDATE policies on `site.site_media` all permit both the owning user (via the `site.sites → core.users → auth_user_id` chain) and any staff member. The DELETE policy (`site_media_delete_staff`) only permits staff. This is almost certainly intentional — the app soft-deletes by setting `deleted_at` via UPDATE, and only staff can permanently purge records. However, the asymmetry is undocumented. A future developer implementing "permanently delete media" who issues a real SQL `DELETE` will get a silent RLS row-not-found result (PostgreSQL returns 0 rows affected, not a 403) rather than an explicit error, making the failure mode difficult to diagnose.
    - **Plain English:** Think of a storage unit where tenants can add items, reorganize them, and mark boxes as "discard" — but only the facility manager can physically remove them from the premises. That's intentional policy here, and it's probably the right call. The issue is there's no sign on the rule. When the next developer tries to let users empty their own storage unit, the door will appear to open but nothing will happen, with no error message explaining why. A one-line comment fixes this entirely.
    - **Evidence:**
        ```sql
        CREATE POLICY site_media_delete_staff ON site.site_media FOR DELETE TO authenticated
            USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()));
        ```
